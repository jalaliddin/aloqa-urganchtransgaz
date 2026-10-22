<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums;
use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use App\Support\Rbac;
use Illuminate\Http\JsonResponse;

/** Reference data for the UI: enum options with labels/colours, map and business settings. */
class MetaController extends Controller
{
    private const ENUMS = [
        Enums\OrganizationType::class, Enums\LaidYearPrecision::class, Enums\TechnicalCondition::class,
        Enums\UsageStatus::class, Enums\LayingType::class, Enums\RiskLevel::class, Enums\ComplexityFlag::class,
        Enums\GeometrySource::class, Enums\SegmentInspectionStatus::class, Enums\DismantlingStatus::class,
        Enums\DismantlingOrderStatus::class, Enums\InspectionStatus::class, Enums\InspectionMemberRole::class,
        Enums\ApprovalType::class, Enums\ApprovalDecision::class, Enums\DocumentType::class, Enums\MaterialType::class,
        Enums\MaterialUnit::class, Enums\MaterialReceiptStatus::class, Enums\WarehouseTransactionType::class,
        Enums\WarehouseTransactionStatus::class, Enums\DismantlingEventType::class, Enums\AlertType::class,
        Enums\AlertSeverity::class, Enums\AlertStatus::class, Enums\Metal::class, Enums\CompositionSourceType::class,
        Enums\CompositionStatus::class, Enums\MetalCalculationTrigger::class,
    ];

    public function __invoke(SettingsService $settings): JsonResponse
    {
        $enums = [];
        foreach (self::ENUMS as $enum) {
            $enums[$enum::translationKey()] = $enum::options();
        }

        return response()->json([
            'data' => [
                'enums' => $enums,
                'roles' => collect(Rbac::ROLES)->map(fn ($r, $name) => ['value' => $name, 'label' => $r['label']])->values(),
                'map' => $settings->map(),
                'tolerance' => $settings->tolerances(),
                'documents' => [
                    'max_kb' => config('aloqa.documents.max_kb'),
                    'allowed_extensions' => config('aloqa.documents.allowed_extensions'),
                    'required' => config('aloqa.documents.required'),
                ],
            ],
        ]);
    }
}
