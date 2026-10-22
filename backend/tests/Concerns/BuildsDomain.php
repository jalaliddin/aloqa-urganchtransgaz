<?php

namespace Tests\Concerns;

use App\Enums\DocumentType;
use App\Enums\InspectionMemberRole;
use App\Enums\LaidYearPrecision;
use App\Enums\OrganizationType;
use App\Enums\RiskLevel;
use App\Enums\TechnicalCondition;
use App\Enums\UsageStatus;
use App\Models\CableLine;
use App\Models\Document;
use App\Models\Inspection;
use App\Models\Organization;
use App\Models\User;
use App\Services\CableRegistryService;
use App\Services\DocumentService;
use App\Services\InspectionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/** Test data built through the real services so statuses stay consistent. */
trait BuildsDomain
{
    private int $orgCounter = 0;

    protected function headOrg(): Organization
    {
        return Organization::query()->firstOrCreate(
            ['code' => 'TEST-HQ'],
            ['type' => OrganizationType::Head, 'name' => 'TEST Boshqaruv', 'short_name' => 'TEST HQ', 'is_demo' => true],
        );
    }

    protected function org(?string $name = null): Organization
    {
        $this->orgCounter++;

        return Organization::query()->create([
            'parent_id' => $this->headOrg()->id,
            'type' => OrganizationType::Mgqb,
            'code' => 'TEST-MGQB-'.$this->orgCounter.'-'.uniqid(),
            'name' => $name ?? "TEST MGQB {$this->orgCounter}",
            'short_name' => $name ?? "TEST MGQB {$this->orgCounter}",
            'is_demo' => true,
        ]);
    }

    protected function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->headOrg())->id]);
        $user->assignRole($role);

        return $user->fresh();
    }

    /** Session (web guard) authentication, exactly like the SPA. Guards are reset so users can be switched mid-test. */
    protected function login(User $user): User
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    /** @return array{type: string, coordinates: list<array{0: float, 1: float}>} */
    protected function lineString(float $lng = 60.60, float $lat = 41.50, int $points = 3, float $step = 0.01): array
    {
        $coordinates = [];
        for ($i = 0; $i < $points; $i++) {
            $coordinates[] = [round($lng + $i * $step, 6), round($lat + $i * $step / 2, 6)];
        }

        return ['type' => 'LineString', 'coordinates' => $coordinates];
    }

    /** @param list<array<string, mixed>>|null $segments */
    protected function line(Organization $org, User $by, ?array $segments = null, array $overrides = []): CableLine
    {
        return app(CableRegistryService::class)->createLine([
            'name' => 'TEST liniya '.uniqid(),
            'brand' => 'MKSB 4x4x1.2',
            'laid_year' => 1965,
            'laid_year_precision' => LaidYearPrecision::Decade->value,
            'organization_id' => $org->id,
            'balance_holder_id' => $org->id,
            'design_length_m' => '1000.00',
            'segments' => $segments ?? [['name' => '1-uchastka', 'design_length_m' => '1000.00', 'laying_type' => 'underground']],
            ...$overrides,
        ], $by);
    }

    protected function pdfUpload(string $name = 'hujjat.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    protected function attachDocument(Model $attachable, DocumentType $type, User $by): Document
    {
        return app(DocumentService::class)->store($this->pdfUpload(), $attachable, ['type' => $type->value, 'title' => 'TEST '.$type->value], $by);
    }

    /** Runs a full inspection (create → record → submit → approve) for every segment of the line. */
    protected function inspectLine(CableLine $line, User $manager, User $approver, string $usage = 'in_use'): Inspection
    {
        $service = app(InspectionService::class);
        $inspection = $service->create([
            'organization_id' => $line->organization_id,
            'title' => 'TEST inventarizatsiya',
            'segment_ids' => $line->segments()->pluck('id')->all(),
            'members' => [['full_name' => 'TEST Rais', 'role' => InspectionMemberRole::Chair->value]],
        ], $manager);
        $service->start($inspection, $manager);

        foreach ($inspection->items()->get() as $item) {
            if ($usage === UsageStatus::NotInUse->value) {
                $this->attachDocument($item, DocumentType::Photo, $manager);
            }
            $service->recordItem($item, [
                'inspected_on' => now()->toDateString(),
                'measured_length_m' => $item->recorded_length_m,
                'technical_condition' => TechnicalCondition::Poor->value,
                'usage_status' => $usage,
                'usage_evidence' => $usage === UsageStatus::NotInUse->value ? 'TEST dalil' : null,
                'risk_level' => $item->segment->risk_level->value ?? RiskLevel::Low->value,
                'complexity_flags' => $item->segment->complexity_flags ?? [],
            ], $manager);
        }

        $service->submit($inspection->refresh(), $manager);

        return $service->approve($inspection->refresh(), $approver, 'TEST tasdiq');
    }
}
