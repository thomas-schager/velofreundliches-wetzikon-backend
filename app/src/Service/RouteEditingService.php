<?php

namespace App\Service;

use App\Entity\AdminUser;
use App\Entity\RouteBackup;
use App\Entity\RouteFeature;
use App\Repository\RouteBackupRepository;
use App\Repository\RouteFeatureRepository;
use App\Repository\RouteTypeRepository;
use App\Service\Exception\RouteBackupNotFoundException;
use App\Service\Exception\ValidationException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Backs the route editor's "load current network / preview a proposed edit / save it" flow --
 * called from Controller\Api\AdminRoutesController. No auto-save: every write is one explicit
 * call to save(), triggered only when an admin clicks "Speichern" in the (future) editor UI --
 * there is no interval timer or on-every-edit persistence anywhere in this service.
 *
 * PUT /admin/routes stays a full replace at the wire level (openapi.yaml, unchanged), but unlike
 * a naive "delete everything, insert everything" implementation, save() reconciles proposed
 * features against existing ones *by id* (see RouteFeaturePresenter's admin-only properties.id)
 * so unchanged/edited lines keep their database id across saves -- needed for diff()/save() to
 * tell "this line was edited" apart from "this line was removed and a new one drawn" on the
 * *next* edit too, not just the current one.
 */
class RouteEditingService
{
    private const DIRECTION_LABELS = [
        'one-way' => 'Einbahn',
        'both-ways' => 'Beidseitig',
    ];

    public function __construct(
        private readonly RouteFeatureRepository $routeFeatures,
        private readonly RouteTypeRepository $routeTypes,
        private readonly RouteBackupRepository $routeBackups,
        private readonly EntityManagerInterface $em,
        private readonly string $routeBackupsDir,
    ) {
    }

    public function getCurrentFeatureCollection(): array
    {
        return RouteFeaturePresenter::toFeatureCollection($this->routeFeatures->findAll(), includeId: true);
    }

    /**
     * Read-only: computes what save() *would* do, without writing anything. Used by the editor
     * to show a change summary before the admin confirms.
     */
    public function previewChanges(array $proposedGeoJson): array
    {
        $routeTypesByKey = $this->routeTypesByKey();
        $proposed = $this->normalizeProposed($proposedGeoJson, $routeTypesByKey);
        $currentById = $this->currentByIdNormalized();

        return $this->buildSummary($this->classify($currentById, $proposed), $routeTypesByKey);
    }

    /**
     * @return array{features: array, changeSummary: array, backupId: ?int}
     */
    public function save(array $proposedGeoJson, AdminUser $admin, ?string $label = null): array
    {
        $routeTypesByKey = $this->routeTypesByKey();
        $proposed = $this->normalizeProposed($proposedGeoJson, $routeTypesByKey);

        $currentEntities = [];
        foreach ($this->routeFeatures->findAll() as $f) {
            $currentEntities[$f->getId()] = $f;
        }
        $currentById = $this->normalizeEntities($currentEntities);
        $oldFeatureCollection = RouteFeaturePresenter::toFeatureCollection($currentEntities, includeId: true);

        $classification = $this->classify($currentById, $proposed);
        $summary = $this->buildSummary($classification, $routeTypesByKey);

        $backupId = null;
        if ($summary['addedCount'] + $summary['removedCount'] + $summary['modifiedCount'] > 0) {
            $backupId = $this->applyChanges($classification, $currentEntities, $routeTypesByKey, $admin, $oldFeatureCollection, $summary, $label);
        }

        return [
            'features' => $this->getCurrentFeatureCollection(),
            'changeSummary' => $summary,
            'backupId' => $backupId,
        ];
    }

    /** @return array<int, array{id: int, filePath: string, createdAt: DateTimeImmutable, createdByEmail: ?string, addedCount: int, removedCount: int, modifiedCount: int, summary: string}> */
    public function listBackups(): array
    {
        return array_map(static fn (RouteBackup $b) => [
            'id' => $b->getId(),
            'createdAt' => $b->getCreatedAt(),
            'createdByEmail' => $b->getCreatedBy()?->getEmail(),
            'addedCount' => $b->getAddedCount(),
            'removedCount' => $b->getRemovedCount(),
            'modifiedCount' => $b->getModifiedCount(),
            'summary' => $b->getSummary(),
        ], $this->routeBackups->findAllOrdered());
    }

    /**
     * Restoring is just another tracked save -- it goes through the exact same save() path, so
     * it also writes its own pre-restore backup first. There is no special-cased "undo" logic
     * that bypasses the normal write path.
     */
    public function restoreBackup(int $backupId, AdminUser $admin): array
    {
        $backup = $this->routeBackups->find($backupId);
        if ($backup === null) {
            throw new RouteBackupNotFoundException("No route backup with id {$backupId}.");
        }

        $path = $this->routeBackupsDir . '/' . $backup->getFilePath();
        if (!is_file($path)) {
            throw new RouteBackupNotFoundException("Backup #{$backupId}'s snapshot file is missing on disk.");
        }

        $geoJson = json_decode((string) file_get_contents($path), true);
        if (!is_array($geoJson)) {
            throw new RouteBackupNotFoundException("Backup #{$backupId}'s snapshot file is unreadable.");
        }

        $label = sprintf('Wiederherstellung von Sicherung #%d (%s)', $backupId, $backup->getCreatedAt()->format('d.m.Y, H:i'));

        return $this->save($geoJson, $admin, $label);
    }

    // -----------------------------------------------------------------------------------------
    // Validation / normalization
    // -----------------------------------------------------------------------------------------

    /** @return array<string, \App\Entity\RouteType> */
    private function routeTypesByKey(): array
    {
        $byKey = [];
        foreach ($this->routeTypes->findAllOrdered() as $t) {
            $byKey[$t->getKey()] = $t;
        }

        return $byKey;
    }

    /**
     * Parses+validates a RouteFeatureCollection request body into a flat list of
     * {id: ?int, routeTypeKey: string, direction: ?string, coordinates: array}. Throws
     * ValidationException (400) on any structural problem -- before this method returns, we know
     * the payload is safe to diff and to persist, so diff() and save() share this exact check.
     *
     * @param array<string, \App\Entity\RouteType> $routeTypesByKey
     */
    private function normalizeProposed(array $geoJson, array $routeTypesByKey): array
    {
        $errors = [];
        if (($geoJson['type'] ?? null) !== 'FeatureCollection' || !is_array($geoJson['features'] ?? null)) {
            throw new ValidationException('Request body failed validation.', ['type' => 'must be a GeoJSON FeatureCollection']);
        }

        $normalized = [];
        $seenIds = [];
        foreach ($geoJson['features'] as $i => $feature) {
            $prefix = "features[{$i}]";
            $geometry = $feature['geometry'] ?? null;
            $coordinates = $geometry['coordinates'] ?? null;
            $properties = $feature['properties'] ?? [];

            if (($feature['type'] ?? null) !== 'Feature' || ($geometry['type'] ?? null) !== 'LineString') {
                $errors[$prefix] = 'must be a GeoJSON Feature with LineString geometry';
                continue;
            }
            if (!is_array($coordinates) || count($coordinates) < 2) {
                $errors["{$prefix}.geometry.coordinates"] = 'must have at least 2 points';
                continue;
            }
            $cleanCoords = [];
            $validCoords = true;
            foreach ($coordinates as $pair) {
                if (!is_array($pair) || count($pair) !== 2 || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                    $validCoords = false;
                    break;
                }
                $cleanCoords[] = [(float) $pair[0], (float) $pair[1]];
            }
            if (!$validCoords) {
                $errors["{$prefix}.geometry.coordinates"] = 'each point must be a [lng, lat] number pair';
                continue;
            }

            $typeKey = $properties['type'] ?? null;
            if (!is_string($typeKey) || !isset($routeTypesByKey[$typeKey])) {
                $errors["{$prefix}.properties.type"] = 'must reference a known route type key';
                continue;
            }

            $direction = $properties['direction'] ?? null;
            if ($direction !== null && !isset(self::DIRECTION_LABELS[$direction])) {
                $errors["{$prefix}.properties.direction"] = "must be one of: " . implode(', ', array_keys(self::DIRECTION_LABELS));
                continue;
            }

            $id = $properties['id'] ?? null;
            $id = (is_int($id) || (is_string($id) && ctype_digit($id))) ? (int) $id : null;
            if ($id !== null) {
                if (isset($seenIds[$id])) {
                    // Duplicate id in the payload -- keep the first claim, treat this one as new
                    // rather than rejecting the whole save over a client-side bug.
                    $id = null;
                } else {
                    $seenIds[$id] = true;
                }
            }

            $normalized[] = [
                'id' => $id,
                'routeTypeKey' => $typeKey,
                'direction' => $direction,
                'coordinates' => $cleanCoords,
            ];
        }

        if ($errors !== []) {
            throw new ValidationException('Request body failed validation.', $errors);
        }

        return $normalized;
    }

    /** @param array<int, RouteFeature> $entities keyed by id */
    private function normalizeEntities(array $entities): array
    {
        $out = [];
        foreach ($entities as $id => $f) {
            $out[$id] = [
                'id' => $id,
                'routeTypeKey' => $f->getRouteType()->getKey(),
                'direction' => $f->getDirection(),
                'coordinates' => $f->getCoordinates(),
            ];
        }

        return $out;
    }

    private function currentByIdNormalized(): array
    {
        $entities = [];
        foreach ($this->routeFeatures->findAll() as $f) {
            $entities[$f->getId()] = $f;
        }

        return $this->normalizeEntities($entities);
    }

    // -----------------------------------------------------------------------------------------
    // Diffing
    // -----------------------------------------------------------------------------------------

    /**
     * @param array<int, array> $currentById   id => normalized feature
     * @param array<int, array> $proposed      normalized proposed features (id may be null)
     * @return array<int, array{action: string, id: ?int, previous: ?array, proposed: ?array}>
     */
    private function classify(array $currentById, array $proposed): array
    {
        $entries = [];
        $matchedIds = [];

        foreach ($proposed as $p) {
            $old = $p['id'] !== null ? ($currentById[$p['id']] ?? null) : null;

            if ($old === null) {
                $entries[] = ['action' => 'added', 'id' => null, 'previous' => null, 'proposed' => $p];
                continue;
            }

            $matchedIds[$p['id']] = true;
            $unchanged = $old['routeTypeKey'] === $p['routeTypeKey']
                && $old['direction'] === $p['direction']
                && $this->coordinatesEqual($old['coordinates'], $p['coordinates']);

            $entries[] = [
                'action' => $unchanged ? 'unchanged' : 'modified',
                'id' => $p['id'],
                'previous' => $old,
                'proposed' => $p,
            ];
        }

        foreach ($currentById as $id => $old) {
            if (!isset($matchedIds[$id])) {
                $entries[] = ['action' => 'removed', 'id' => $id, 'previous' => $old, 'proposed' => null];
            }
        }

        return $entries;
    }

    private function coordinatesEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $pair) {
            if (abs($pair[0] - $b[$i][0]) > 1e-7 || abs($pair[1] - $b[$i][1]) > 1e-7) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, \App\Entity\RouteType> $routeTypesByKey
     */
    private function buildSummary(array $entries, array $routeTypesByKey): array
    {
        $added = $removed = $modified = $unchanged = 0;
        $byType = []; // routeTypeLabel => string[] lines, grouping for readability
        $structured = [];

        foreach ($entries as $entry) {
            if ($entry['action'] === 'unchanged') {
                $unchanged++;
                continue;
            }

            $data = $entry['proposed'] ?? $entry['previous'];
            $label = $routeTypesByKey[$data['routeTypeKey']]->getLabel();
            $location = $this->locationLabel($data['coordinates']);

            if ($entry['action'] === 'added') {
                $added++;
                $line = "Neue Strecke hinzugefügt: {$label}" . $this->directionSuffix($data['direction']) . ", bei {$location}";
            } elseif ($entry['action'] === 'removed') {
                $removed++;
                $line = "Strecke entfernt: {$label}" . $this->directionSuffix($data['direction']) . ", bei {$location}";
            } else {
                $modified++;
                $changes = [];
                $prev = $entry['previous'];
                $prop = $entry['proposed'];
                if ($prev['routeTypeKey'] !== $prop['routeTypeKey']) {
                    $oldLabel = $routeTypesByKey[$prev['routeTypeKey']]->getLabel();
                    $changes[] = "Typ geändert von „{$oldLabel}" . '" zu „' . "{$label}\"";
                }
                if ($prev['direction'] !== $prop['direction']) {
                    $changes[] = 'Richtung geändert von ' . $this->directionLabel($prev['direction']) . ' zu ' . $this->directionLabel($prop['direction']);
                }
                if (!$this->coordinatesEqual($prev['coordinates'], $prop['coordinates'])) {
                    $changes[] = sprintf('Verlauf angepasst (%d → %d Punkte)', count($prev['coordinates']), count($prop['coordinates']));
                }
                $detail = $changes !== [] ? implode('; ', $changes) : 'geändert';
                $line = "Strecke geändert ({$label}, bei {$location}): {$detail}";
            }

            $byType[$label][] = $line;
            $structured[] = array_merge(['label' => $label, 'location' => $location], $entry);
        }

        $lines = [];
        foreach ($byType as $label => $groupLines) {
            foreach ($groupLines as $line) {
                $lines[] = $line;
            }
        }

        return [
            'addedCount' => $added,
            'removedCount' => $removed,
            'modifiedCount' => $modified,
            'unchangedCount' => $unchanged,
            'lines' => $lines,
            'entries' => $structured,
        ];
    }

    private function directionSuffix(?string $direction): string
    {
        return $direction !== null ? ' (' . $this->directionLabel($direction) . ')' : '';
    }

    private function directionLabel(?string $direction): string
    {
        if ($direction === null) {
            return 'ohne Richtungsangabe';
        }

        return self::DIRECTION_LABELS[$direction] ?? $direction;
    }

    private function locationLabel(array $coordinates): string
    {
        $latSum = 0.0;
        $lngSum = 0.0;
        foreach ($coordinates as $pair) {
            $lngSum += $pair[0];
            $latSum += $pair[1];
        }
        $n = count($coordinates);

        return sprintf('%.4f, %.4f', $latSum / $n, $lngSum / $n);
    }

    // -----------------------------------------------------------------------------------------
    // Persistence
    // -----------------------------------------------------------------------------------------

    /**
     * @param array<int, RouteFeature> $currentEntities keyed by id
     * @param array<string, \App\Entity\RouteType> $routeTypesByKey
     */
    private function applyChanges(array $classification, array $currentEntities, array $routeTypesByKey, AdminUser $admin, array $oldFeatureCollection, array $summary, ?string $label): int
    {
        $fs = new Filesystem();
        $fs->mkdir($this->routeBackupsDir);
        $fileName = sprintf('%s-%s.geojson', (new DateTimeImmutable())->format('Ymd-His'), bin2hex(random_bytes(4)));
        $fs->dumpFile($this->routeBackupsDir . '/' . $fileName, json_encode($oldFeatureCollection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $summaryText = ($label !== null ? $label . "\n" : '') . implode("\n", array_map(static fn ($l) => "- {$l}", $summary['lines']));

        $backup = (new RouteBackup())
            ->setFilePath($fileName)
            ->setCreatedBy($admin)
            ->setCounts($summary['addedCount'], $summary['removedCount'], $summary['modifiedCount'])
            ->setSummary($summaryText);

        $this->em->wrapInTransaction(function () use ($classification, $currentEntities, $routeTypesByKey, $backup) {
            $this->em->persist($backup);

            foreach ($classification as $entry) {
                if ($entry['action'] === 'unchanged') {
                    continue;
                }

                if ($entry['action'] === 'added') {
                    $p = $entry['proposed'];
                    $feature = (new RouteFeature())
                        ->setRouteType($routeTypesByKey[$p['routeTypeKey']])
                        ->setDirection($p['direction'])
                        ->setCoordinates($p['coordinates']);
                    $this->em->persist($feature);
                } elseif ($entry['action'] === 'removed') {
                    $this->em->remove($currentEntities[$entry['id']]);
                } else { // modified
                    $p = $entry['proposed'];
                    $feature = $currentEntities[$entry['id']];
                    $feature->setRouteType($routeTypesByKey[$p['routeTypeKey']])
                        ->setDirection($p['direction'])
                        ->setCoordinates($p['coordinates']);
                    $feature->touch();
                }
            }

            $this->em->flush();
        });

        return $backup->getId();
    }
}
