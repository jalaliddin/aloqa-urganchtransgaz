<?php

use App\Enums\DocumentType;

return [

    /*
    | Map provider defaults. Administrators can override them at runtime
    | (Settings → Xarita) without redeploying; see SettingsService.
    */
    'map' => [
        'tile_url' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => env('MAP_TILE_ATTRIBUTION', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> mualliflari'),
        'max_zoom' => (int) env('MAP_MAX_ZOOM', 19),
        'default_center' => [(float) env('MAP_DEFAULT_LAT', 41.55), (float) env('MAP_DEFAULT_LNG', 60.63)],
        'default_zoom' => (int) env('MAP_DEFAULT_ZOOM', 8),
        // Satellite imagery as a second base layer; an empty URL hides it
        'satellite_url' => env('MAP_SATELLITE_URL', 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}') ?: null,
        'satellite_attribution' => env('MAP_SATELLITE_ATTRIBUTION', 'Tasvirlar &copy; <a href="https://www.esri.com">Esri</a>, Maxar, Earthstar Geographics'),
        // Deepest zoom the imagery provider serves; closer zoom levels upscale these tiles
        'satellite_max_native_zoom' => (int) env('MAP_SATELLITE_MAX_NATIVE_ZOOM', 18),
        // Place names and borders drawn over the imagery ("Gibrid"); an empty URL hides that variant
        'satellite_labels_url' => env('MAP_SATELLITE_LABELS_URL', 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}') ?: null,
    ],

    'geometry' => [
        // Coordinates outside this box are rejected (Uzbekistan with a margin)
        'allowed_bbox' => [
            'min_lat' => 36.5, 'max_lat' => 46.5,
            'min_lng' => 55.0, 'max_lng' => 74.0,
        ],
        'max_vertices' => 20000,
        // Geometry change is "suspicious" when length changes by more than this share…
        'suspicious_length_change_percent' => 25,
        // …or when the geometry centre moves further than this
        'suspicious_shift_m' => 2000,
    ],

    'tolerance' => [
        'length_m' => (float) env('LENGTH_TOLERANCE_M', 5),
        'length_percent' => (float) env('LENGTH_TOLERANCE_PERCENT', 2),
        'material_percent' => (float) env('MATERIAL_TOLERANCE_PERCENT', 1),
        // Default for non-ferrous metal mass comparisons; overridable in Settings
        'metal_percent' => (float) env('METAL_TOLERANCE_PERCENT', 3),
    ],

    'documents' => [
        'disk' => 'local',
        'max_kb' => (int) env('DOCUMENT_MAX_KB', 51200),
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'mp4', 'mov', 'zip'],
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg', 'image/png', 'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'video/mp4', 'video/quicktime',
            'application/zip',
        ],

        // Mandatory documents per stage (spec 4.7). Used for alerts, KPIs and reports.
        'required' => [
            'cable_line' => [DocumentType::TechnicalPassport->value],
            'dismantling_order_completed' => [DocumentType::DismantlingAct->value],
            'dismantling_order_left_in_place' => [DocumentType::LeaveInPlaceDecision->value],
            'material_receipt' => [DocumentType::WarehouseReceipt->value],
        ],
    ],

    'auth' => [
        'max_login_attempts' => 5,
        'lockout_seconds' => 60,
    ],
];
