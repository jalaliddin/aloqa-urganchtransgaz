<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime-configurable settings. Defaults come from config/aloqa.php (.env);
 * administrators override them without redeploying.
 */
final class SettingsService
{
    // Bump the suffix when the shape changes, so a deploy never serves an older cached shape
    private const CACHE_KEY = 'settings.map.v2';

    /** @return array<string, mixed> */
    public function map(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $stored = Setting::query()->where('key', 'map')->value('value') ?? [];
            // A stored null means the administrator switched the layer off, so it must not fall back to the default
            $optional = fn (string $key) => array_key_exists($key, $stored) ? $stored[$key] : config("aloqa.map.$key");

            return [
                'tile_url' => $stored['tile_url'] ?? config('aloqa.map.tile_url'),
                'attribution' => $stored['attribution'] ?? config('aloqa.map.attribution'),
                'max_zoom' => (int) ($stored['max_zoom'] ?? config('aloqa.map.max_zoom')),
                'default_center' => $stored['default_center'] ?? config('aloqa.map.default_center'),
                'default_zoom' => (int) ($stored['default_zoom'] ?? config('aloqa.map.default_zoom')),
                'satellite_url' => $optional('satellite_url'),
                'satellite_attribution' => $optional('satellite_attribution'),
                'satellite_max_native_zoom' => (int) ($stored['satellite_max_native_zoom'] ?? config('aloqa.map.satellite_max_native_zoom')),
                'satellite_labels_url' => $optional('satellite_labels_url'),
                // Optional overlays from a corporate GIS / GeoServer (WMS)
                'wms_layers' => $stored['wms_layers'] ?? [],
            ];
        });
    }

    /** @param array<string, mixed> $data */
    public function updateMap(array $data, User $user): array
    {
        // Attribution is rendered as HTML by Leaflet: keep only plain links
        $data['attribution'] = $this->sanitizeAttribution($data['attribution']);
        if (isset($data['satellite_attribution'])) {
            $data['satellite_attribution'] = $this->sanitizeAttribution($data['satellite_attribution']);
        }
        foreach ($data['wms_layers'] ?? [] as $i => $layer) {
            $data['wms_layers'][$i]['attribution'] = $this->sanitizeAttribution($layer['attribution'] ?? '');
        }

        Setting::query()->updateOrCreate(['key' => 'map'], ['value' => $data, 'updated_by' => $user->id]);
        Cache::forget(self::CACHE_KEY);

        return $this->map();
    }

    public function sanitizeAttribution(string $html): string
    {
        $clean = strip_tags($html, '<a>');
        // Drop every attribute except a safe http(s) href
        $clean = preg_replace_callback('/<a\b[^>]*>/i', function (array $m): string {
            preg_match('/href\s*=\s*"(https?:\/\/[^"]*)"/i', $m[0], $href);

            return isset($href[1]) ? '<a href="'.e($href[1]).'" target="_blank" rel="noopener noreferrer">' : '<a>';
        }, $clean) ?? '';

        return trim($clean);
    }

    /** @return array<string, float> */
    public function tolerances(): array
    {
        return [...config('aloqa.tolerance'), 'metal_percent' => $this->metalTolerancePercent()];
    }

    /** Allowed difference (%) between theoretical, act and warehouse metal masses. Differences are always shown. */
    public function metalTolerancePercent(): float
    {
        $stored = Cache::rememberForever('settings.metal_tolerance', fn () => Setting::query()->where('key', 'metal_tolerance_percent')->value('value'));

        return (float) ($stored ?? config('aloqa.tolerance.metal_percent'));
    }

    public function updateMetalTolerance(float $percent, User $user): float
    {
        Setting::query()->updateOrCreate(['key' => 'metal_tolerance_percent'], ['value' => $percent, 'updated_by' => $user->id]);
        Cache::forget('settings.metal_tolerance');

        return $this->metalTolerancePercent();
    }
}
