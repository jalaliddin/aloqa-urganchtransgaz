<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CableLineController;
use App\Http\Controllers\Api\CableSegmentController;
use App\Http\Controllers\Api\CableTypeController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DismantlingOrderController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\GasPipelineController;
use App\Http\Controllers\Api\InspectionController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\MetalCompositionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json(['status' => 'ok']));

Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    Route::get('meta', MetaController::class);
    Route::get('dashboard', DashboardController::class);

    Route::apiResource('organizations', OrganizationController::class)->only(['index', 'store', 'update']);
    Route::apiResource('gas-pipelines', GasPipelineController::class)->only(['index', 'store', 'update'])
        ->parameters(['gas-pipelines' => 'gasPipeline']);

    // Registry
    Route::get('cable-lines/{cableLine}/timeline', [CableLineController::class, 'timeline']);
    Route::get('cable-lines/{cableLine}/metal-content', [CableLineController::class, 'metalContent']);

    // Cable types and non-ferrous metal content (kg/m)
    Route::apiResource('cable-types', CableTypeController::class)->only(['index', 'show', 'store', 'update'])
        ->parameters(['cable-types' => 'cableType']);
    Route::get('metal-compositions', [MetalCompositionController::class, 'index']);
    Route::post('metal-compositions', [MetalCompositionController::class, 'store']);
    Route::post('metal-compositions/{metalComposition}/approve', [MetalCompositionController::class, 'approve']);
    Route::post('metal-compositions/{metalComposition}/reject', [MetalCompositionController::class, 'reject']);
    Route::apiResource('cable-lines', CableLineController::class)->parameters(['cable-lines' => 'cableLine']);
    Route::post('cable-lines/{cableLine}/segments', [CableSegmentController::class, 'store']);
    Route::get('cable-segments', [CableSegmentController::class, 'index']);
    Route::get('cable-segments/{cableSegment}', [CableSegmentController::class, 'show']);
    Route::put('cable-segments/{cableSegment}', [CableSegmentController::class, 'update']);
    Route::delete('cable-segments/{cableSegment}', [CableSegmentController::class, 'destroy']);
    Route::put('cable-segments/{cableSegment}/geometry', [CableSegmentController::class, 'updateGeometry']);
    Route::delete('cable-segments/{cableSegment}/geometry', [CableSegmentController::class, 'clearGeometry']);

    // Map
    Route::get('map/features', [MapController::class, 'features']);
    Route::get('map/missing-geometry', [MapController::class, 'missingGeometry']);
    Route::get('map/export', [MapController::class, 'export'])->middleware('throttle:exports');
    Route::post('map/import', [MapController::class, 'import'])->middleware('throttle:uploads');

    // Inventory / technical inspection
    Route::apiResource('inspections', InspectionController::class)->except(['destroy']);
    Route::post('inspections/{inspection}/start', [InspectionController::class, 'start']);
    Route::post('inspections/{inspection}/submit', [InspectionController::class, 'submit']);
    Route::post('inspections/{inspection}/approve', [InspectionController::class, 'approve']);
    Route::post('inspections/{inspection}/reject', [InspectionController::class, 'reject']);
    Route::post('inspections/{inspection}/cancel', [InspectionController::class, 'cancel']);
    Route::put('inspections/{inspection}/items/{item}', [InspectionController::class, 'recordItem'])->scopeBindings();
    Route::get('inspections/{inspection}/protocol', [InspectionController::class, 'protocol'])->middleware('throttle:exports');

    // Dismantling
    Route::apiResource('dismantling-orders', DismantlingOrderController::class)->except(['destroy'])
        ->parameters(['dismantling-orders' => 'dismantlingOrder']);
    Route::post('dismantling-orders/{dismantlingOrder}/transition', [DismantlingOrderController::class, 'transition']);
    Route::post('dismantling-orders/{dismantlingOrder}/approvals', [DismantlingOrderController::class, 'storeApproval']);
    Route::post('dismantling-orders/{dismantlingOrder}/approvals/{approval}/revoke', [DismantlingOrderController::class, 'revokeApproval']);
    Route::post('dismantling-orders/{dismantlingOrder}/events', [DismantlingOrderController::class, 'storeEvent']);
    Route::put('dismantling-orders/{dismantlingOrder}/act-masses', [DismantlingOrderController::class, 'actMasses']);
    Route::get('dismantling-orders/{dismantlingOrder}/act', [DismantlingOrderController::class, 'act'])->middleware('throttle:exports');

    // Materials and warehouse
    Route::get('material-receipts/receivable-orders', [MaterialController::class, 'receivableOrders']);
    Route::get('material-receipts', [MaterialController::class, 'index']);
    Route::post('material-receipts', [MaterialController::class, 'store']);
    Route::get('material-receipts/{materialReceipt}', [MaterialController::class, 'show']);
    Route::post('material-receipts/{materialReceipt}/decision', [MaterialController::class, 'decide']);
    Route::post('material-items/{materialItem}/transactions', [MaterialController::class, 'move']);
    Route::post('warehouse-transactions/{warehouseTransaction}/decision', [MaterialController::class, 'decideTransaction']);

    // Documents (files are only reachable through the authorised download endpoint)
    Route::get('documents', [DocumentController::class, 'index']);
    Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:uploads');
    Route::get('documents/{document}/download', [DocumentController::class, 'download']);
    Route::post('documents/{document}/void', [DocumentController::class, 'void']);

    // Reports
    Route::get('reports', [ReportController::class, 'index']);
    Route::get('reports/{key}', [ReportController::class, 'show']);
    Route::get('reports/{key}/export', [ReportController::class, 'export'])->middleware('throttle:exports');

    // Users and roles
    Route::get('users/directory', [UserController::class, 'directory']);
    Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);
    Route::get('roles', [RoleController::class, 'index']);
    Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions']);

    // Audit and alerts
    Route::get('audit-logs/events', [AuditLogController::class, 'events']);
    Route::get('audit-logs', [AuditLogController::class, 'index']);
    Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
    Route::get('alerts', [AlertController::class, 'index']);
    Route::post('alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge']);
    Route::post('alerts/{alert}/resolve', [AlertController::class, 'resolve']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);

    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings/map', [SettingsController::class, 'updateMap']);
    Route::put('settings/tolerance', [SettingsController::class, 'updateTolerance']);
});
