<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        return response()->json(['data' => ['map' => $this->settings->map(), 'tolerance' => $this->settings->tolerances()]]);
    }

    public function updateMap(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $data = $request->validate([
            // Tile template must be http(s) and contain the {z}/{x}/{y} placeholders
            'tile_url' => ['required', 'string', 'max:500', 'regex:/^https?:\/\/[^\s]+$/', 'regex:/\{z\}/', 'regex:/\{x\}/', 'regex:/\{y\}/'],
            'attribution' => ['required', 'string', 'max:500'],
            'max_zoom' => ['required', 'integer', 'min:1', 'max:22'],
            'default_center' => ['required', 'array', 'size:2'],
            'default_center.0' => ['required', 'numeric', 'between:-90,90'],
            'default_center.1' => ['required', 'numeric', 'between:-180,180'],
            'default_zoom' => ['required', 'integer', 'min:1', 'max:22'],
            // Satellite base layer is optional: an empty URL hides it from the map
            'satellite_url' => ['nullable', 'string', 'max:500', 'regex:/^https?:\/\/[^\s]+$/', 'regex:/\{z\}/', 'regex:/\{x\}/', 'regex:/\{y\}/'],
            'satellite_attribution' => ['nullable', 'required_with:satellite_url', 'string', 'max:500'],
            'satellite_max_native_zoom' => ['nullable', 'integer', 'min:1', 'max:22'],
            'satellite_labels_url' => ['nullable', 'string', 'max:500', 'regex:/^https?:\/\/[^\s]+$/', 'regex:/\{z\}/', 'regex:/\{x\}/', 'regex:/\{y\}/'],
            'wms_layers' => ['nullable', 'array', 'max:10'],
            'wms_layers.*.name' => ['required', 'string', 'max:100'],
            'wms_layers.*.url' => ['required', 'string', 'max:500', 'regex:/^https?:\/\/[^\s]+$/'],
            'wms_layers.*.layers' => ['required', 'string', 'max:255'],
            'wms_layers.*.format' => ['nullable', 'string', 'in:image/png,image/jpeg'],
            'wms_layers.*.transparent' => ['boolean'],
            'wms_layers.*.attribution' => ['nullable', 'string', 'max:255'],
        ]);

        $old = $this->settings->map();
        $map = $this->settings->updateMap($data, $request->user());
        $audit->log('settings_updated', null, ['map' => $old], ['map' => $map]);

        return response()->json(['data' => ['map' => $map, 'tolerance' => $this->settings->tolerances()]]);
    }

    public function updateTolerance(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $data = $request->validate([
            'metal_percent' => ['required', 'numeric', 'min:0', 'max:50', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $old = $this->settings->metalTolerancePercent();
        $new = $this->settings->updateMetalTolerance((float) $data['metal_percent'], $request->user());
        $audit->log('settings_updated', null, ['metal_tolerance_percent' => $old], ['metal_tolerance_percent' => $new], $data['reason']);

        return response()->json(['data' => ['map' => $this->settings->map(), 'tolerance' => $this->settings->tolerances()]]);
    }
}
