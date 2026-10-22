<?php

namespace Database\Seeders;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalType;
use App\Enums\ComplexityFlag;
use App\Enums\CompositionSourceType;
use App\Enums\DismantlingEventType;
use App\Enums\DismantlingOrderStatus;
use App\Enums\DocumentType;
use App\Enums\GeometrySource;
use App\Enums\InspectionMemberRole;
use App\Enums\LaidYearPrecision;
use App\Enums\LayingType;
use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Enums\OrganizationType;
use App\Enums\RiskLevel;
use App\Enums\TechnicalCondition;
use App\Enums\UsageStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CableLine;
use App\Models\CableSegment;
use App\Models\CableType;
use App\Models\DismantlingOrder;
use App\Models\Document;
use App\Models\GasPipelineAsset;
use App\Models\Inspection;
use App\Models\Organization;
use App\Models\User;
use App\Services\CableRegistryService;
use App\Services\DismantlingWorkflow;
use App\Services\DocumentService;
use App\Services\GeometryService;
use App\Services\InspectionService;
use App\Services\MaterialService;
use App\Services\MetalCompositionService;
use App\Services\MetalMassCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Development / demonstration data. Everything is explicitly marked DEMO:
 * organisation and user names, is_demo flags and geometry_source = demo.
 * Coordinates are synthetic and do NOT describe real cable routes.
 *
 * Data is created through the same services the API uses, so statuses,
 * audit log entries and alerts are consistent with real workflows.
 */
class DemoSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Organization> */
    private array $orgs = [];

    public function run(
        CableRegistryService $registry,
        InspectionService $inspections,
        DismantlingWorkflow $dismantling,
        MaterialService $materials,
        DocumentService $documents,
        GeometryService $geometry,
        MetalCompositionService $compositions,
    ): void {
        if (app()->isProduction() && ! env('ALLOW_DEMO_SEED')) {
            throw new RuntimeException('DemoSeeder production muhitida ishga tushirilmaydi.');
        }

        if (Organization::query()->where('code', 'DEMO-HQ')->exists()) {
            $this->command?->warn('DEMO ma\'lumotlar allaqachon mavjud — o\'tkazib yuborildi.');

            return;
        }

        mt_srand(20261022);
        $this->createOrganizationsAndUsers();
        $pipelines = $this->createPipelines();
        $types = $this->createCableTypes();
        $lines = $this->createLines($registry, $pipelines, $types);
        $this->attachRegistryDocuments($documents, $lines);
        $this->createCompositions($compositions, $types, $lines);

        $this->runWorkflows($registry, $inspections, $dismantling, $materials, $documents, $lines);

        Auth::guard('web')->logout();
        $this->command?->info('DEMO ma\'lumotlar yaratildi. Parol: '.$this->password());
    }

    private function password(): string
    {
        return (string) env('DEMO_PASSWORD', 'Demo12345');
    }

    private function createOrganizationsAndUsers(): void
    {
        $head = Organization::query()->create([
            'type' => OrganizationType::Head, 'code' => 'DEMO-HQ', 'name' => 'DEMO Boshqaruv (markaziy apparat)',
            'short_name' => 'DEMO Boshqaruv', 'is_demo' => true,
        ]);
        $this->orgs['head'] = $head;

        foreach ([1 => 'Shimoliy', 2 => 'Markaziy', 3 => 'Janubiy'] as $n => $label) {
            $this->orgs["mgqb{$n}"] = Organization::query()->create([
                'parent_id' => $head->id, 'type' => OrganizationType::Mgqb, 'code' => "DEMO-MGQB-{$n}",
                'name' => "DEMO MGQB-{$n} ({$label})", 'short_name' => "DEMO MGQB-{$n}", 'is_demo' => true,
            ]);
        }

        $people = [
            'admin' => ['DEMO Administrator', 'super_admin', 'head', 'Tizim administratori'],
            'rahbar' => ['DEMO Rahbariyat vakili', 'management', 'head', 'Bosh muhandis o\'rinbosari'],
            'at' => ['DEMO AT xizmati muhandisi', 'at_service', 'head', 'AT xizmati yetakchi muhandisi'],
            'mgqb1' => ['DEMO MGQB-1 mas\'uli', 'mgqb_manager', 'mgqb1', 'MGQB aloqa bo\'yicha mas\'ul'],
            'mgqb2' => ['DEMO MGQB-2 mas\'uli', 'mgqb_manager', 'mgqb2', 'MGQB aloqa bo\'yicha mas\'ul'],
            'brigadir' => ['DEMO Ish rahbari (brigadir)', 'mgqb_manager', 'mgqb1', 'Demontaj brigadasi rahbari'],
            'komissiya' => ['DEMO Texnik komissiya raisi', 'tech_commission', 'mgqb1', 'Komissiya raisi'],
            'xavfsizlik' => ['DEMO Sanoat xavfsizligi muhandisi', 'safety', 'mgqb1', 'Sanoat xavfsizligi muhandisi'],
            'ombor' => ['DEMO Ombor mudiri', 'warehouse_keeper', 'mgqb1', 'Moddiy javobgar shaxs'],
            'buxgalter' => ['DEMO Buxgalter', 'accountant', 'head', 'Bosh buxgalter o\'rinbosari'],
            'auditor' => ['DEMO Auditor', 'auditor', 'head', 'Ichki auditor'],
        ];

        foreach ($people as $key => [$name, $role, $org, $position]) {
            $user = User::query()->create([
                'organization_id' => $this->orgs[$org]->id,
                'name' => $name,
                'email' => "{$key}@demo.aloqa.local",
                'position' => $position,
                'password' => $this->password(),
                'is_active' => true,
            ]);
            $user->assignRole($role);
            $this->users[$key] = $user;
        }
    }

    /** @return array<string, GasPipelineAsset> */
    private function createPipelines(): array
    {
        $result = [];
        foreach ([1 => 720, 2 => 1020, 3 => 530] as $n => $diameter) {
            $result["mgqb{$n}"] = GasPipelineAsset::query()->create([
                'organization_id' => $this->orgs["mgqb{$n}"]->id,
                'code' => "DEMO-MGQ-{$n}",
                'name' => "DEMO magistral gaz quvuri MGQ-{$n}",
                'diameter_mm' => $diameter,
                'is_demo' => true,
            ]);
        }

        return $result;
    }

    /**
     * @param  array<string, GasPipelineAsset>  $pipelines
     * @param  array<string, CableType>  $types
     * @return array<string, CableLine>
     */
    private function createLines(CableRegistryService $registry, array $pipelines, array $types): array
    {
        $this->actAs('at');
        $centres = ['mgqb1' => [60.40, 41.85], 'mgqb2' => [60.63, 41.55], 'mgqb3' => [61.00, 41.30]];
        $brands = ['MKSB 4x4x1.2', 'MKPAB 7x4x1.05', 'TZG 4x4x1.2', 'KSPP 1x4x0.9'];
        $years = [[1964, LaidYearPrecision::Decade], [1972, LaidYearPrecision::Exact], [null, LaidYearPrecision::Unknown], [1981, LaidYearPrecision::Approximate]];
        $lines = [];
        $n = 0;

        foreach ($centres as $orgKey => [$lng, $lat]) {
            for ($i = 1; $i <= 4; $i++) {
                $n++;
                [$year, $precision] = $years[($n - 1) % 4];
                $segmentCount = 1 + ($n % 3);
                $segments = [];
                $start = [$lng + ($i - 2.5) * 0.06, $lat + (($i % 2) ? 0.03 : -0.03)];

                for ($s = 1; $s <= $segmentCount; $s++) {
                    $path = $this->demoPath($start, 4 + ($s % 3) * 2);
                    $start = end($path);
                    $complex = ($n === 4 && $s === 1) || ($n === 7 && $s === 2);
                    $segments[] = [
                        'name' => "{$s}-uchastka",
                        'location_description' => "DEMO hudud {$n}.{$s}",
                        'laying_type' => $complex ? LayingType::NearBuilding->value : ($s === 2 ? LayingType::RoadCrossing->value : LayingType::Underground->value),
                        'depth_m' => $complex ? '2.40' : '0.90',
                        'design_length_m' => (string) (int) round((float) app(GeometryService::class)->lengthMeters(['type' => 'LineString', 'coordinates' => $path]) / 10) * 10,
                        'technical_condition' => [TechnicalCondition::Satisfactory, TechnicalCondition::Poor, TechnicalCondition::Unknown][$s % 3]->value,
                        'usage_status' => UsageStatus::Unknown->value,
                        'risk_level' => $complex ? RiskLevel::High->value : RiskLevel::Low->value,
                        'complexity_flags' => $complex ? [ComplexityFlag::UnderBuilding->value, ComplexityFlag::NearGasPipeline->value] : null,
                        // Every third line keeps its last segment without geometry to demonstrate "geometriya kiritilmagan"
                        'geometry' => ($n % 3 === 0 && $s === $segmentCount) ? null : ['type' => 'LineString', 'coordinates' => $path],
                        'geometry_source' => GeometrySource::Demo->value,
                    ];
                }

                $design = array_sum(array_map(fn ($seg) => (float) $seg['design_length_m'], $segments));
                $line = $registry->createLine([
                    'name' => "DEMO aloqa kabeli liniyasi №{$n}",
                    'cable_code' => sprintf('DEMO-KAB-%03d', $n),
                    'cable_type' => 'Simmetrik aloqa kabeli',
                    'brand' => $brands[$n % 4],
                    'cable_type_id' => $types[$brands[$n % 4]]->id,
                    'core_count' => [16, 28, 16, 4][$n % 4],
                    'laid_year' => $year,
                    'laid_year_precision' => $precision->value,
                    'organization_id' => $this->orgs[$orgKey]->id,
                    'balance_holder_id' => $this->orgs[$orgKey]->id,
                    'gas_pipeline_asset_id' => $pipelines[$orgKey]->id,
                    'object_name' => "DEMO KS-{$n} — DEMO GTS-{$n}",
                    'start_point' => "DEMO KS-{$n}",
                    'end_point' => "DEMO GTS-{$n}",
                    'design_length_m' => number_format($design, 2, '.', ''),
                    'inventory_number' => sprintf('DEMO-INV-%05d', 1000 + $n),
                    'initial_cost' => number_format(12000000 + $n * 850000, 2, '.', ''),
                    'accumulated_depreciation' => number_format(11500000 + $n * 800000, 2, '.', ''),
                    'residual_value' => number_format(500000 + $n * 50000, 2, '.', ''),
                    'notes' => 'DEMO yozuv: koordinatalar shartli, haqiqiy trassa emas.',
                    'segments' => $segments,
                ], $this->users['at']);
                $line->forceFill(['is_demo' => true])->saveQuietly();
                $lines["L{$n}"] = $line;
            }
        }

        return $lines;
    }

    /**
     * DEMO cable types. kg/m values below are illustrative only — not real normative data.
     *
     * @return array<string, CableType>
     */
    private function createCableTypes(): array
    {
        $types = [];
        foreach (['MKSB 4x4x1.2' => 16, 'MKPAB 7x4x1.05' => 28, 'TZG 4x4x1.2' => 16, 'KSPP 1x4x0.9' => 4] as $brand => $cores) {
            $types[$brand] = CableType::query()->create([
                'code' => 'DEMO-'.str_replace([' ', '.'], ['-', ''], $brand),
                'name' => $brand,
                'brand' => strtok($brand, ' '),
                'core_count' => $cores,
                'description' => 'DEMO kabel turi — tarkib qiymatlari namunaviy, haqiqiy normativ emas.',
                'is_demo' => true,
                'created_by' => $this->users['at']->id,
            ]);
        }

        return $types;
    }

    /**
     * Type-level kg/m entered by the AT service and approved by management (two people).
     * TZG has unknown aluminium (NULL ≠ 0); KSPP has no composition at all.
     *
     * @param  array<string, CableType>  $types
     * @param  array<string, CableLine>  $lines
     */
    private function createCompositions(MetalCompositionService $service, array $types, array $lines): void
    {
        $values = [
            'MKSB 4x4x1.2' => ['aluminium_kg_per_m' => '0', 'lead_kg_per_m' => '1.8500', 'copper_kg_per_m' => '0.3620'],
            'MKPAB 7x4x1.05' => ['aluminium_kg_per_m' => '0.4210', 'lead_kg_per_m' => '0', 'copper_kg_per_m' => '0.5480'],
            'TZG 4x4x1.2' => ['aluminium_kg_per_m' => null, 'lead_kg_per_m' => '1.2040', 'copper_kg_per_m' => '0.3620'],
        ];

        foreach ($values as $brand => $kg) {
            $this->actAs('at');
            $composition = $service->submit($types[$brand], [
                ...$kg,
                'source_type' => CompositionSourceType::BrandNormative->value,
                'source_reference' => 'DEMO qiymat — haqiqiy normativ emas',
            ], $this->users['at']);
            $composition->forceFill(['is_demo' => true])->saveQuietly();
            $this->actAs('rahbar');
            $service->approve($composition, $this->users['rahbar'], 'DEMO: namunaviy qiymat tasdiqlandi.');
        }

        // A measured value for one segment, waiting for a second person's approval
        $this->actAs('mgqb1');
        $segment = $lines['L2']->segments()->orderBy('sequence')->first();
        $pending = $service->submit($segment, [
            'aluminium_kg_per_m' => '0.4380', 'lead_kg_per_m' => '0', 'copper_kg_per_m' => '0.5310',
            'source_type' => CompositionSourceType::Measurement->value,
            'source_reference' => 'DEMO: 1 m namunani tortish natijasi',
            'note' => 'DEMO: uchastkada boshqa partiya kabeli yotqizilgani aniqlangan.',
        ], $this->users['mgqb1']);
        $pending->forceFill(['is_demo' => true])->saveQuietly();
    }

    /** @param array<string, CableLine> $lines */
    private function attachRegistryDocuments(DocumentService $documents, array $lines): void
    {
        foreach ([1, 2, 3, 5, 6, 9] as $n) {
            $this->attach($documents, $lines["L{$n}"], DocumentType::TechnicalPassport, 'DEMO texnik pasport', 'at');
        }
        $this->attach($documents, $lines['L1'], DocumentType::OldScheme, 'DEMO eski sxema (skan)', 'at');
    }

    /** @param array<string, CableLine> $lines */
    private function runWorkflows(
        CableRegistryService $registry,
        InspectionService $inspections,
        DismantlingWorkflow $dismantling,
        MaterialService $materials,
        DocumentService $documents,
        array $lines,
    ): void {
        $u = $this->users;
        $members = [
            ['user_id' => $u['komissiya']->id, 'full_name' => $u['komissiya']->name, 'position' => $u['komissiya']->position, 'role' => InspectionMemberRole::Chair->value],
            ['user_id' => $u['at']->id, 'full_name' => $u['at']->name, 'position' => $u['at']->position, 'role' => InspectionMemberRole::Member->value],
            ['user_id' => null, 'full_name' => 'DEMO Tashqi mutaxassis', 'position' => 'Aloqa operatori vakili', 'role' => InspectionMemberRole::Member->value],
        ];

        // ── 1. Approved inspection of lines 1, 2 and 4 (MGQB-1) ─────────────────
        $this->actAs('mgqb1');
        $segmentIds = CableSegment::query()->whereIn('cable_line_id', [$lines['L1']->id, $lines['L2']->id, $lines['L4']->id])->pluck('id')->all();
        $inv1 = $inspections->create([
            'organization_id' => $this->orgs['mgqb1']->id,
            'title' => 'DEMO: 1-, 2- va 4-liniyalarni inventarizatsiya qilish',
            'planned_start' => now()->subMonths(5)->toDateString(),
            'planned_end' => now()->subMonths(4)->toDateString(),
            'segment_ids' => $segmentIds,
            'members' => $members,
        ], $u['mgqb1']);
        $inspections->start($inv1, $u['mgqb1']);

        foreach ($inv1->items()->with('segment')->get() as $index => $item) {
            $recorded = (float) $item->recorded_length_m;
            $discrepant = $index === 1;
            $notInUse = $item->segment->cable_line_id !== $lines['L4']->id;
            $measured = $discrepant ? $recorded - max(60, $recorded * 0.08) : $recorded + 2;

            if ($discrepant) {
                $this->attach($documents, $item, DocumentType::CommissionProtocol, 'DEMO o\'lchov bayonnomasi', 'mgqb1');
            }
            if ($notInUse) {
                $this->attach($documents, $item, DocumentType::Photo, 'DEMO foto: kabel uchlari ajratilgan', 'mgqb1', photo: true);
            }

            $inspections->recordItem($item, [
                'inspected_on' => now()->subMonths(4)->toDateString(),
                'measured_length_m' => number_format($measured, 2, '.', ''),
                'difference_reason' => $discrepant ? 'DEMO: trassaning bir qismi avval boshqa loyiha doirasida demontaj qilingan.' : null,
                'technical_condition' => TechnicalCondition::Poor->value,
                'usage_status' => $notInUse ? UsageStatus::NotInUse->value : UsageStatus::InUse->value,
                'usage_evidence' => $notInUse ? 'DEMO: aloqa uzeli bilan ulanish yo\'q, kabel uchlari ajratilgan (foto ilova).' : null,
                'risk_level' => $item->segment->risk_level->value,
                'complexity_flags' => $item->segment->complexity_flags ?? [],
                'findings' => 'DEMO ko\'rik natijasi.',
            ], $u['mgqb1']);
        }
        $inspections->submit($inv1->refresh(), $u['mgqb1']);
        $this->actAs('komissiya');
        $inspections->approve($inv1->refresh(), $u['komissiya'], 'DEMO: komissiya xulosasi tasdiqlandi.');
        $inv1->forceFill(['is_demo' => true])->saveQuietly();

        // ── 2. Completed dismantling of line 1 with warehouse intake ────────────
        $this->actAs('mgqb1');
        $dm1 = $this->order($dismantling, $lines['L1'], 'DEMO-IR-01', $u['brigadir'], -4);
        $dismantling->transition($dm1, DismantlingOrderStatus::PendingReview, $u['mgqb1']);
        $dismantling->transition($dm1, DismantlingOrderStatus::PendingAgreement, $u['mgqb1']);
        $this->recordAgreements($dismantling, $documents, $dm1);
        $this->actAs('rahbar');
        $dismantling->transition($dm1, DismantlingOrderStatus::Approved, $u['rahbar'], 'DEMO: barcha kelishuvlar mavjud.');
        $this->actAs('mgqb1');
        $dismantling->transition($dm1, DismantlingOrderStatus::InProgress, $u['mgqb1']);

        $this->actAs('brigadir');
        $planned = (float) $dm1->refresh()->planned_length_m;
        foreach ([[-3, 0.45], [-2, 0.35], [-1, 0.18]] as [$months, $share]) {
            $dismantling->recordEvent($dm1, [
                'type' => DismantlingEventType::Progress->value,
                'occurred_at' => now()->addMonths($months)->toDateTimeString(),
                'extracted_length_m' => number_format($planned * $share, 2, '.', ''),
                'description' => 'DEMO: kabel chiqarildi va g\'altakka o\'raldi.',
            ], $u['brigadir']);
        }
        $act = $this->attach($documents, $dm1, DocumentType::DismantlingAct, 'DEMO demontaj dalolatnomasi', 'mgqb1');
        $this->actAs('mgqb1');
        $dismantling->transition($dm1, DismantlingOrderStatus::Completed, $u['mgqb1']);

        // Masses per the dismantling act — recorded separately from the theoretical figure
        $theory = app(MetalMassCalculator::class)->orderBalance($dm1->refresh())['metals'];
        $actKg = fn (string $metal, float $share) => $theory[$metal]['theoretical_kg'] === null ? null : number_format((float) $theory[$metal]['theoretical_kg'] * $share, 3, '.', '');
        $dismantling->recordActMasses($dm1, [
            'aluminium_kg' => $actKg('aluminium', 0.985),
            'lead_kg' => $actKg('lead', 1),
            'copper_kg' => $actKg('copper', 0.99),
            'document_id' => $act->id,
        ], $u['mgqb1']);
        $dm1->refresh();
        // Demo history: align actual dates with the back-dated progress records
        $dm1->forceFill(['actual_start' => now()->subMonths(3)->subDays(5)->toDateString(), 'actual_end' => now()->subMonth()->addDays(3)->toDateString()])->saveQuietly();

        $this->actAs('ombor');
        $dismantled = (float) $dm1->refresh()->actual_length_m;
        $receipt = $materials->receive([
            'dismantling_order_id' => $dm1->id,
            'number' => 'DEMO-OK-0001',
            'receipt_date' => now()->subDays(20)->toDateString(),
            'warehouse_name' => 'DEMO markaziy ombor',
            'handed_over_by' => $u['brigadir']->id,
            'notes' => 'DEMO kirim.',
            'items' => [
                [
                    'material_type' => MaterialType::CableWhole->value, 'unit' => MaterialUnit::M->value,
                    'handed_over_quantity' => number_format($dismantled, 3, '.', ''),
                    'accepted_quantity' => number_format($dismantled - 120, 3, '.', ''),
                    'measured_on' => now()->subDays(20)->toDateString(), 'storage_location' => 'DEMO A-3 stellaj',
                    'description' => 'G\'altakdagi kabel',
                ],
                [
                    'material_type' => MaterialType::Aluminium->value, 'unit' => MaterialUnit::Kg->value,
                    'handed_over_quantity' => (string) $dm1->act_aluminium_kg,
                    // ~4 % below the act: shown as a discrepancy needing review, not equalised
                    'accepted_quantity' => number_format((float) $dm1->act_aluminium_kg * 0.96, 3, '.', ''),
                    'measured_on' => now()->subDays(20)->toDateString(), 'storage_location' => 'DEMO metall maydoncha',
                    'description' => 'Alyuminiy qobiq (tarozi)',
                ],
                [
                    'material_type' => MaterialType::Copper->value, 'unit' => MaterialUnit::Kg->value,
                    'handed_over_quantity' => (string) $dm1->act_copper_kg,
                    'accepted_quantity' => number_format((float) $dm1->act_copper_kg * 0.997, 3, '.', ''),
                    'measured_on' => now()->subDays(20)->toDateString(), 'storage_location' => 'DEMO metall maydoncha',
                    'description' => 'Mis tomirlar (tarozi)',
                ],
                [
                    'material_type' => MaterialType::ScrapMetal->value, 'unit' => MaterialUnit::Kg->value,
                    'handed_over_quantity' => '420.000', 'accepted_quantity' => '418.500',
                    'measured_on' => now()->subDays(20)->toDateString(), 'storage_location' => 'DEMO metall maydoncha',
                    'description' => 'Po\'lat zirh qoldiqlari (tarozi)',
                ],
            ],
        ], $u['ombor']);
        $receipt->forceFill(['is_demo' => true])->saveQuietly();
        $this->attach($documents, $receipt, DocumentType::WarehouseReceipt, 'DEMO ombor kirim orderi', 'ombor');
        $dm1->forceFill(['is_demo' => true])->saveQuietly();

        // ── 3. Line 2: waiting for the operation agreement ───────────────────────
        $this->actAs('mgqb1');
        $dm2 = $this->order($dismantling, $lines['L2'], 'DEMO-IR-02', $u['brigadir'], 1);
        $dismantling->transition($dm2, DismantlingOrderStatus::PendingReview, $u['mgqb1']);
        $dismantling->transition($dm2, DismantlingOrderStatus::PendingAgreement, $u['mgqb1']);
        $this->actAs('xavfsizlik');
        $dismantling->recordApproval($dm2, [
            'type' => ApprovalType::SafetyAgreement->value, 'decision' => ApprovalDecision::Approved->value,
            'reference_number' => 'DEMO-SX-02', 'reference_date' => now()->subDays(3)->toDateString(),
            'comment' => 'DEMO: sanoat xavfsizligi talablari bilan kelishildi.',
        ], $u['xavfsizlik']);
        // Attempt without all agreements → refused and recorded as an alert (demonstrates the control)
        $this->actAs('rahbar');
        try {
            $dismantling->transition($dm2, DismantlingOrderStatus::Approved, $u['rahbar']);
        } catch (BusinessRuleException) {
        }
        $dm2->forceFill(['is_demo' => true])->saveQuietly();

        // ── 4. Line 4 (complex, under a building): left in place by decision ────
        $this->actAs('mgqb1');
        $dm4 = $this->order($dismantling, $lines['L4'], 'DEMO-IR-04', $u['brigadir'], 2);
        $dismantling->transition($dm4, DismantlingOrderStatus::PendingReview, $u['mgqb1']);
        $dismantling->transition($dm4, DismantlingOrderStatus::LeaveInPlacePending, $u['mgqb1'], 'DEMO: uchastka turar-joy binosi ostidan o\'tadi, demontaj xavfli.');
        $this->actAs('rahbar');
        $decision = $this->attach($documents, $dm4, DocumentType::LeaveInPlaceDecision, 'DEMO joyida qoldirish qarori', 'rahbar');
        $dismantling->recordApproval($dm4, [
            'type' => ApprovalType::LeaveInPlaceDecision->value, 'decision' => ApprovalDecision::Approved->value,
            'reference_number' => 'DEMO-Q-04', 'reference_date' => now()->subDays(10)->toDateString(),
            'comment' => 'DEMO: bino poydevoriga zarar yetkazish xavfi sababli kabel joyida qoldiriladi, uchlari izolyatsiya qilinadi.',
            'document_id' => $decision->id,
        ], $u['rahbar']);
        $dismantling->transition($dm4, DismantlingOrderStatus::LeftInPlace, $u['rahbar'], 'DEMO: qaror asosida.');
        $dm4->forceFill(['is_demo' => true])->saveQuietly();

        // ── 5. Inspection in progress (line 3) and one awaiting approval (MGQB-2) ─
        $this->actAs('mgqb1');
        $inv2 = $inspections->create([
            'organization_id' => $this->orgs['mgqb1']->id,
            'title' => 'DEMO: 3-liniya texnik ko\'rigi',
            'planned_start' => now()->subDays(5)->toDateString(),
            'planned_end' => now()->addDays(10)->toDateString(),
            'segment_ids' => $lines['L3']->segments()->pluck('id')->all(),
            'members' => $members,
        ], $u['mgqb1']);
        $inspections->start($inv2, $u['mgqb1']);
        $first = $inv2->items()->first();
        $inspections->recordItem($first, [
            'inspected_on' => now()->subDays(2)->toDateString(),
            'measured_length_m' => $first->recorded_length_m,
            'technical_condition' => TechnicalCondition::Satisfactory->value,
            'usage_status' => UsageStatus::InUse->value,
            'risk_level' => RiskLevel::Low->value,
        ], $u['mgqb1']);
        $inv2->forceFill(['is_demo' => true])->saveQuietly();

        $this->actAs('mgqb2');
        $inv3 = $inspections->create([
            'organization_id' => $this->orgs['mgqb2']->id,
            'title' => 'DEMO: 5-liniya inventarizatsiyasi',
            'planned_start' => now()->subDays(20)->toDateString(),
            'segment_ids' => $lines['L5']->segments()->pluck('id')->all(),
            'members' => [['user_id' => $u['mgqb2']->id, 'full_name' => $u['mgqb2']->name, 'position' => null, 'role' => InspectionMemberRole::Chair->value]],
        ], $u['mgqb2']);
        $inspections->start($inv3, $u['mgqb2']);
        foreach ($inv3->items as $item) {
            $inspections->recordItem($item, [
                'inspected_on' => now()->subDays(7)->toDateString(),
                'measured_length_m' => $item->recorded_length_m,
                'technical_condition' => TechnicalCondition::Satisfactory->value,
                'usage_status' => UsageStatus::InUse->value,
                'risk_level' => RiskLevel::Low->value,
            ], $u['mgqb2']);
        }
        $inspections->submit($inv3->refresh(), $u['mgqb2']);
        $inv3->forceFill(['is_demo' => true])->saveQuietly();

        // ── 6. A draft dismantling order in MGQB-2 ───────────────────────────────
        $dm6 = $this->order($dismantling, $lines['L6'], 'DEMO-IR-06', null, 3);
        $dm6->forceFill(['is_demo' => true])->saveQuietly();
    }

    private function order(DismantlingWorkflow $dismantling, CableLine $line, string $plan, ?User $supervisor, int $startInMonths): DismantlingOrder
    {
        return $dismantling->create([
            'cable_line_id' => $line->id,
            'segments' => $line->segments()->pluck('id')->map(fn ($id) => ['id' => $id])->all(),
            'work_plan_number' => $plan,
            'order_number' => 'DEMO-B-'.substr($plan, -2),
            'order_date' => now()->addMonths($startInMonths - 1)->toDateString(),
            'brigade_name' => 'DEMO aloqa brigadasi №1',
            'work_supervisor_id' => $supervisor?->id,
            'planned_start' => now()->addMonths($startInMonths)->toDateString(),
            'planned_end' => now()->addMonths($startInMonths + 1)->toDateString(),
            'notes' => 'DEMO topshiriq.',
        ], Auth::user());
    }

    private function recordAgreements(DismantlingWorkflow $dismantling, DocumentService $documents, DismantlingOrder $order): void
    {
        $this->actAs('xavfsizlik');
        foreach ([ApprovalType::SafetyAgreement, ApprovalType::OperationAgreement] as $type) {
            $dismantling->recordApproval($order, [
                'type' => $type->value, 'decision' => ApprovalDecision::Approved->value,
                'reference_number' => 'DEMO-K-'.$order->id.'-'.$type->value, 'reference_date' => now()->subMonths(4)->toDateString(),
                'external_party' => $type === ApprovalType::OperationAgreement ? 'DEMO ekspluatatsiya xizmati' : null,
                'comment' => 'DEMO kelishuv.',
            ], $this->users['xavfsizlik']);
        }

        if ($order->is_complex) {
            $this->actAs('komissiya');
            $dismantling->recordApproval($order, [
                'type' => ApprovalType::SpecialistReview->value, 'decision' => ApprovalDecision::Approved->value,
                'reference_number' => 'DEMO-MK-'.$order->id, 'comment' => 'DEMO: murakkab uchastka ko\'rikdan o\'tkazildi.',
            ], $this->users['komissiya']);
        }

        $this->attach($documents, $order, DocumentType::WorkPermit, 'DEMO naryad-dopusk', 'xavfsizlik');
    }

    private function attach(DocumentService $documents, Model $attachable, DocumentType $type, string $title, string $as, bool $photo = false): Document
    {
        $path = tempnam(sys_get_temp_dir(), 'demo');
        if ($photo) {
            $image = imagecreatetruecolor(640, 400);
            imagefill($image, 0, 0, imagecolorallocate($image, 233, 236, 239));
            imagestring($image, 5, 220, 190, 'DEMO FOTO', imagecolorallocate($image, 201, 42, 42));
            imagepng($image, $path);
            $file = new UploadedFile($path, 'demo-foto.png', 'image/png', null, true);
        } else {
            file_put_contents($path, Pdf::loadHTML('<h2 style="font-family: DejaVu Sans">DEMO HUJJAT</h2><p style="font-family: DejaVu Sans">'.e($title).'<br>Bu hujjat namunaviy (demo) ma\'lumot, haqiqiy hujjat emas.</p>')->output());
            $file = new UploadedFile($path, 'demo-hujjat.pdf', 'application/pdf', null, true);
        }

        return $documents->store($file, $attachable, [
            'type' => $type->value,
            'title' => $title,
            'number' => 'DEMO-'.mt_rand(100, 999),
            'document_date' => now()->subDays(mt_rand(1, 120))->toDateString(),
        ], $this->users[$as]);
    }

    private function actAs(string $key): void
    {
        Auth::guard('web')->setUser($this->users[$key]);
    }

    /**
     * Synthetic random-walk path (NOT a real route).
     *
     * @param  array{0: float, 1: float}  $start
     * @return list<array{0: float, 1: float}>
     */
    private function demoPath(array $start, int $vertices): array
    {
        $path = [$start];
        $heading = mt_rand(0, 360);
        for ($i = 1; $i < $vertices; $i++) {
            $heading += mt_rand(-35, 35);
            $step = mt_rand(250, 650) / 111000; // metres → degrees (approx.)
            [$lng, $lat] = end($path);
            $path[] = [round($lng + $step * cos(deg2rad($heading)) / cos(deg2rad($lat)), 6), round($lat + $step * sin(deg2rad($heading)), 6)];
        }

        return $path;
    }
}
