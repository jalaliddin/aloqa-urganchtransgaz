<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Geometry abstraction for cable segments. Geometry is stored as GeoJSON
 * (LineString / MultiLineString, WGS84, [lng, lat]) plus derived bbox and length.
 * Swapping storage to native MySQL spatial columns only touches this class and
 * the segment bbox query in MapController.
 */
final class GeometryService
{
    private const EARTH_RADIUS_M = 6371008.8;

    private const PRECISION = 7;

    /**
     * Accepts a Geometry, Feature or FeatureCollection of line geometries and returns a
     * validated, normalised Geometry. Throws ValidationException under $field.
     *
     * @param  array<string, mixed>  $input
     * @return array{type: string, coordinates: array<int, mixed>}
     */
    public function normalize(array $input, string $field = 'geometry'): array
    {
        $lines = $this->extractLines($input, $field);

        if ($lines === []) {
            $this->fail($field, "Geometriya bo'sh: kamida bitta chiziq (LineString) bo'lishi kerak.");
        }

        $bbox = config('aloqa.geometry.allowed_bbox');
        $vertexCount = 0;
        $normalised = [];

        foreach ($lines as $lineIndex => $positions) {
            if (! is_array($positions) || count($positions) < 2) {
                $this->fail($field, sprintf('%d-chiziqda kamida 2 ta nuqta bo\'lishi kerak.', $lineIndex + 1));
            }

            $line = [];
            foreach ($positions as $pointIndex => $position) {
                $line[] = $this->validatePosition($position, $bbox, $field, $lineIndex, $pointIndex);
            }

            // Drop consecutive duplicates, then make sure the line still has length
            $line = array_values(array_filter($line, static fn ($p, $i) => $i === 0 || $p !== $line[$i - 1], ARRAY_FILTER_USE_BOTH));
            if (count($line) < 2) {
                $this->fail($field, sprintf('%d-chiziq nol uzunlikka ega (barcha nuqtalar bir xil).', $lineIndex + 1));
            }

            $vertexCount += count($line);
            $normalised[] = $line;
        }

        if ($vertexCount > config('aloqa.geometry.max_vertices')) {
            $this->fail($field, sprintf('Nuqtalar soni juda ko\'p (%d). Maksimal: %d.', $vertexCount, config('aloqa.geometry.max_vertices')));
        }

        return count($normalised) === 1
            ? ['type' => 'LineString', 'coordinates' => $normalised[0]]
            : ['type' => 'MultiLineString', 'coordinates' => $normalised];
    }

    /** Geodesic (haversine) length in metres, 2 decimals, as a decimal string. */
    public function lengthMeters(array $geometry): string
    {
        $total = 0.0;
        foreach ($this->lines($geometry) as $line) {
            for ($i = 1, $n = count($line); $i < $n; $i++) {
                $total += $this->distance($line[$i - 1], $line[$i]);
            }
        }

        return number_format($total, 2, '.', '');
    }

    /** @return array{min_lat: float, min_lng: float, max_lat: float, max_lng: float} */
    public function bbox(array $geometry): array
    {
        $lats = [];
        $lngs = [];
        foreach ($this->lines($geometry) as $line) {
            foreach ($line as [$lng, $lat]) {
                $lngs[] = $lng;
                $lats[] = $lat;
            }
        }

        return ['min_lat' => min($lats), 'min_lng' => min($lngs), 'max_lat' => max($lats), 'max_lng' => max($lngs)];
    }

    public function vertexCount(array $geometry): int
    {
        return array_sum(array_map('count', $this->lines($geometry)));
    }

    /**
     * Derived columns to persist next to the geometry.
     *
     * @return array<string, mixed>
     */
    public function derivedAttributes(array $geometry): array
    {
        $bbox = $this->bbox($geometry);

        return [
            'geometry' => $geometry,
            'geometry_length_m' => $this->lengthMeters($geometry),
            'bbox_min_lat' => $bbox['min_lat'],
            'bbox_min_lng' => $bbox['min_lng'],
            'bbox_max_lat' => $bbox['max_lat'],
            'bbox_max_lng' => $bbox['max_lng'],
        ];
    }

    /**
     * A change is suspicious when the length differs by more than the configured share
     * or the geometry centre moves further than the configured distance.
     *
     * @return array{suspicious: bool, length_change_percent: float|null, shift_m: float|null}
     */
    public function compare(?array $old, array $new): array
    {
        if (empty($old)) {
            return ['suspicious' => false, 'length_change_percent' => null, 'shift_m' => null];
        }

        $oldLength = (float) $this->lengthMeters($old);
        $newLength = (float) $this->lengthMeters($new);
        $change = $oldLength > 0 ? abs($newLength - $oldLength) / $oldLength * 100 : null;
        $shift = $this->distance($this->centre($old), $this->centre($new));

        $suspicious = ($change !== null && $change > config('aloqa.geometry.suspicious_length_change_percent'))
            || $shift > config('aloqa.geometry.suspicious_shift_m');

        return [
            'suspicious' => $suspicious,
            'length_change_percent' => $change !== null ? round($change, 1) : null,
            'shift_m' => round($shift, 1),
        ];
    }

    /** @return list<list<array{0: float, 1: float}>> */
    public function lines(array $geometry): array
    {
        return match ($geometry['type'] ?? null) {
            'LineString' => [$geometry['coordinates']],
            'MultiLineString' => $geometry['coordinates'],
            default => [],
        };
    }

    /** @return array{0: float, 1: float} [lng, lat] */
    private function centre(array $geometry): array
    {
        $bbox = $this->bbox($geometry);

        return [($bbox['min_lng'] + $bbox['max_lng']) / 2, ($bbox['min_lat'] + $bbox['max_lat']) / 2];
    }

    /** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b */
    private function distance(array $a, array $b): float
    {
        [$lng1, $lat1] = array_map('deg2rad', $a);
        [$lng2, $lat2] = array_map('deg2rad', $b);
        $h = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(($lng2 - $lng1) / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($h)));
    }

    /** @return list<mixed> */
    private function extractLines(array $input, string $field): array
    {
        $type = $input['type'] ?? null;

        return match ($type) {
            'LineString' => [$input['coordinates'] ?? null],
            'MultiLineString' => array_values((array) ($input['coordinates'] ?? [])),
            'Feature' => is_array($input['geometry'] ?? null)
                ? $this->extractLines($input['geometry'], $field)
                : $this->fail($field, 'Feature obyektida geometry mavjud emas.'),
            'FeatureCollection' => array_merge(...array_map(
                fn ($feature) => is_array($feature) ? $this->extractLines($feature, $field) : [],
                array_values((array) ($input['features'] ?? [])),
            ) ?: [[]]),
            default => $this->fail($field, sprintf(
                "Qo'llab-quvvatlanmaydigan geometriya turi: %s. Faqat LineString yoki MultiLineString (Feature/FeatureCollection ichida ham) qabul qilinadi.",
                is_string($type) ? $type : 'noma\'lum',
            )),
        };
    }

    /**
     * @param  array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}  $bbox
     * @return array{0: float, 1: float}
     */
    private function validatePosition(mixed $position, array $bbox, string $field, int $lineIndex, int $pointIndex): array
    {
        $where = sprintf('%d-chiziq, %d-nuqta', $lineIndex + 1, $pointIndex + 1);

        if (! is_array($position) || count($position) < 2 || ! is_numeric($position[0] ?? null) || ! is_numeric($position[1] ?? null)) {
            $this->fail($field, "{$where}: koordinata [uzunlik, kenglik] ko'rinishidagi sonlar juftligi bo'lishi kerak.");
        }

        $lng = (float) $position[0];
        $lat = (float) $position[1];

        if (! is_finite($lng) || ! is_finite($lat) || $lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
            $this->fail($field, "{$where}: koordinata ruxsat etilgan oraliqdan tashqarida.");
        }

        $inside = static fn (float $x, float $y) => $y >= $bbox['min_lat'] && $y <= $bbox['max_lat'] && $x >= $bbox['min_lng'] && $x <= $bbox['max_lng'];

        if (! $inside($lng, $lat)) {
            $hint = $inside($lat, $lng) ? " Koordinatalar tartibi almashgan bo'lishi mumkin: GeoJSON'da [uzunlik, kenglik] tartibi talab qilinadi." : '';
            $this->fail($field, "{$where}: nuqta xizmat ko'rsatish hududidan tashqarida ({$lat}, {$lng}).{$hint}");
        }

        return [round($lng, self::PRECISION), round($lat, self::PRECISION)];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
