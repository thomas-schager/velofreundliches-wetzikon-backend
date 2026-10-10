<?php

namespace App\Service;

use App\Entity\Report;

/**
 * Formats Report entities into the wire shapes openapi.yaml defines (PublicReport / AdminReport).
 * Shared by the public API, admin API, and admin Twig pages so the field mapping (id formatting,
 * "anonymous" display name, etc.) lives in exactly one place.
 */
class ReportPresenter
{
    public static function formatId(int $id): string
    {
        return 'm-' . $id;
    }

    public static function parseId(string $formatted): ?int
    {
        if (!preg_match('/^m-(\d+)$/', $formatted, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Same 20m/50m thresholds as the frontend's formatAddressLabel() (velomelder-gelbes-band.html)
     * -- keep both in sync if the thresholds ever change. Returns a ready-to-embed prepositional
     * phrase ("bei X" / "in der Nähe von X"), not a bare label, since callers need different
     * connecting words depending on which case applies when dropping this into a sentence.
     * Shared by ReportSubmissionService (confirmation emails) and ReportModerationService
     * (publish notification) rather than duplicated in each.
     */
    public static function addressPhraseForEmail(?string $address, ?float $distanceM): ?string
    {
        if ($address === null || $distanceM === null) {
            return null;
        }
        if ($distanceM <= 20) {
            return 'bei ' . $address;
        }
        if ($distanceM <= 50) {
            return 'in der Nähe von ' . $address;
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function toPublicArray(Report $report): array
    {
        return [
            'id' => self::formatId($report->getId()),
            'lat' => $report->getLat(),
            'lng' => $report->getLng(),
            'rating' => $report->getRating(),
            'comment' => $report->getComment(),
            // url = "large" (full-screen lightbox only), displayUrl = smaller/lower-quality
            // (everywhere else -- thumbnails, the "main" preview) -- see PhotoConversionService.
            'photos' => array_map(static fn ($p) => ['url' => $p->getUrl(), 'displayUrl' => $p->getDisplayUrl()], $report->getPhotos()->toArray()),
            'name' => $report->isAnonymous() ? null : $report->getName(),
            'anonymous' => $report->isAnonymous(),
            'address' => $report->getAddress(),
            'addressDistanceM' => $report->getAddressDistanceM(),
            'createdAt' => $report->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function toAdminArray(Report $report): array
    {
        $moderator = $report->getModeratedBy();

        return array_merge(self::toPublicArray($report), [
            // AdminReport shows the real name/photos regardless of the anonymous flag -- the
            // admin UI needs to see and edit what "anonymous" is hiding from the public view.
            'name' => $report->getName(),
            'source' => $report->getSource(),
            'email' => $report->getEmail(),
            'emailConfirmed' => $report->isEmailConfirmed(),
            'status' => $report->getStatus(),
            'version' => $report->getVersion(),
            'internalNote' => $report->getInternalNote(),
            'moderatedBy' => $moderator?->getEmail(),
            'moderatedAt' => $report->getModeratedAt()?->format(DATE_ATOM),
        ]);
    }
}
