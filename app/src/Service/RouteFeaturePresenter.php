<?php

namespace App\Service;

use App\Entity\RouteFeature;

/**
 * RouteFeature entity <-> GeoJSON Feature array, shared by the public GET /routes
 * (RegistryController), the admin GET /admin/routes, and RouteEditingService's diff/backup logic
 * -- one serialization, not three hand-kept copies.
 */
class RouteFeaturePresenter
{
    /**
     * @param bool $includeId Admin-only: exposes the DB id in properties.id so the (future)
     *                        editor can round-trip it on save, letting RouteEditingService tell
     *                        "this exact line was edited" apart from "this line was removed and
     *                        a new one drawn". Never set for the public API -- openapi.yaml's
     *                        PublicReport-equivalent RouteFeature schema has no id field.
     */
    public static function toFeature(RouteFeature $f, bool $includeId = false): array
    {
        $properties = ['type' => $f->getRouteType()->getKey()];
        if ($f->getDirection() !== null) {
            $properties['direction'] = $f->getDirection();
        }
        if ($includeId) {
            $properties['id'] = $f->getId();
        }

        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $f->getCoordinates(),
            ],
            'properties' => $properties,
        ];
    }

    /**
     * @param iterable<RouteFeature> $features
     */
    public static function toFeatureCollection(iterable $features, bool $includeId = false): array
    {
        $out = [];
        foreach ($features as $f) {
            $out[] = self::toFeature($f, $includeId);
        }

        return ['type' => 'FeatureCollection', 'features' => $out];
    }
}
