<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\GeometrySource;
use App\Http\Controllers\Controller;
use App\Http\Filters\CableFilter;
use App\Models\CableLine;
use App\Models\CableSegment;
use App\Services\AuditLogger;
use App\Services\CableRegistryService;
use App\Services\GeometryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GeoJSON endpoints for the interactive map. Same filters as the registry. */
class MapController extends Controller
{
    public function __construct(
        private readonly GeometryService $geometry,
        private readonly CableRegistryService $registry,
        private readonly AuditLogger $audit,
    ) {}

    /** Segments with geometry inside the viewport as a FeatureCollection. */
    public function features(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CableLine::class);
        $filters = $request->validate([
            ...CableFilter::rules(),
            'bbox' => ['nullable', 'string', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'line_id' => ['nullable', 'integer'],
        ]);

        $segments = $this->segmentQuery($request, $filters)
            ->whereNotNull('cable_segments.geometry')
            ->when($filters['bbox'] ?? null, function (Builder $q, string $bbox): void {
                [$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', explode(',', $bbox));
                // Bounding boxes intersect
                $q->where('bbox_max_lng', '>=', $minLng)->where('bbox_min_lng', '<=', $maxLng)
                    ->where('bbox_max_lat', '>=', $minLat)->where('bbox_min_lat', '<=', $maxLat);
            })
            ->limit(5000)
            ->get();

        return response()->json($this->featureCollection($segments));
    }

    /** Lines matching the filters that have segments without geometry ("geometriya kiritilmagan"). */
    public function missingGeometry(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CableLine::class);
        $filters = $request->validate(CableFilter::rules());

        $segments = $this->segmentQuery($request, $filters)
            ->whereNull('cable_segments.geometry')
            ->orderBy('code')
            ->limit(500)
            ->get();

        return response()->json(['data' => $segments->map(fn (CableSegment $s) => [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'line_id' => $s->cable_line_id,
            'registry_number' => $s->cableLine->registry_number,
            'line_name' => $s->cableLine->name,
            'organization' => $s->cableLine->organization?->short_name,
            'location_description' => $s->location_description,
            'dismantling_status' => $s->dismantling_status->value,
        ])]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', CableLine::class);
        $filters = $request->validate([...CableFilter::rules(), 'line_id' => ['nullable', 'integer']]);
        $segments = $this->segmentQuery($request, $filters)->whereNotNull('cable_segments.geometry')->get();

        $this->audit->log('map_exported', null, null, ['filters' => $filters, 'features' => $segments->count()]);
        $json = json_encode($this->featureCollection($segments, full: true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return response()->streamDownload(fn () => print ($json), 'kabel-trassalari-'.now()->format('Ymd-His').'.geojson', [
            'Content-Type' => 'application/geo+json',
        ]);
    }

    /**
     * Imports geometries for existing segments. Each feature must carry
     * properties.segment_code (or segment_id). All-or-nothing; dry_run only validates.
     */
    public function import(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('cable_lines.edit_geometry'), 403);
        $data = $request->validate([
            'file' => ['required_without:geojson', 'file', 'max:20480'],
            'geojson' => ['required_without:file', 'array'],
            'source' => ['required', Rule::in([GeometrySource::Gps->value, GeometrySource::Import->value])],
            'source_note' => ['required', 'string', 'max:255'],
            'dry_run' => ['boolean'],
        ]);

        $payload = $data['geojson'] ?? json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($payload) || ($payload['type'] ?? null) !== 'FeatureCollection' || ! is_array($payload['features'] ?? null)) {
            throw ValidationException::withMessages(['file' => ["Fayl GeoJSON FeatureCollection formatida bo'lishi kerak."]]);
        }

        $report = [];
        $valid = [];
        foreach (array_values($payload['features']) as $index => $feature) {
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $segment = $this->findSegmentForImport($properties);
            $row = ['index' => $index, 'segment_code' => $properties['segment_code'] ?? null, 'status' => 'ok', 'message' => null];

            try {
                // Same message for "missing" and "not allowed" so codes of other organisations cannot be probed
                if ($segment === null || ! $request->user()->can('editGeometry', $segment)) {
                    throw ValidationException::withMessages(['feature' => ["Uchastka topilmadi yoki unga ruxsatingiz yo'q (properties.segment_code yoki segment_id kerak)."]]);
                }
                $geometry = $this->geometry->normalize(is_array($feature) ? $feature : [], 'feature');
                $row['segment_code'] = $segment->code;
                $row['length_m'] = $this->geometry->lengthMeters($geometry);
                $row['replaces_existing'] = $segment->hasGeometry();
                $valid[] = [$segment, $geometry];
            } catch (ValidationException $e) {
                $row['status'] = 'error';
                $row['message'] = collect($e->errors())->flatten()->first();
            }
            $report[] = $row;
        }

        $errors = collect($report)->where('status', 'error')->count();
        if ($errors > 0 || ($data['dry_run'] ?? false)) {
            return response()->json([
                'message' => $errors > 0 ? "Importda {$errors} ta xato bor — hech narsa saqlanmadi." : 'Tekshiruv muvaffaqiyatli. Saqlash uchun dry_run=false bilan yuboring.',
                'applied' => false,
                'report' => $report,
            ], $errors > 0 ? 422 : 200);
        }

        DB::transaction(function () use ($valid, $data, $request): void {
            foreach ($valid as [$segment, $geometry]) {
                $this->registry->updateGeometry($segment, $geometry, GeometrySource::from($data['source']), $data['source_note'], $request->user());
            }
        });
        $this->audit->log('geojson_imported', null, null, ['features' => count($valid), 'source_note' => $data['source_note']]);

        return response()->json(['message' => count($valid).' ta uchastka geometriyasi saqlandi.', 'applied' => true, 'report' => $report]);
    }

    /** @param array<string, mixed> $filters */
    private function segmentQuery(Request $request, array $filters): Builder
    {
        $query = CableSegment::query()->visibleTo($request->user())
            ->with(['cableLine:id,registry_number,cable_code,name,organization_id,gas_pipeline_asset_id,laid_year,laid_year_precision,is_demo', 'cableLine.organization:id,short_name,name', 'cableLine.gasPipelineAsset:id,name'])
            ->when($filters['line_id'] ?? null, fn ($q, $id) => $q->where('cable_line_id', $id));

        return CableFilter::applyToSegments($query, $filters);
    }

    private function featureCollection($segments, bool $full = false): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => $segments->map(fn (CableSegment $s) => [
                'type' => 'Feature',
                'id' => $s->id,
                'geometry' => $s->geometry,
                'properties' => [
                    'segment_id' => $s->id,
                    'segment_code' => $s->code,
                    'segment_name' => $s->name,
                    'line_id' => $s->cable_line_id,
                    'registry_number' => $s->cableLine->registry_number,
                    'cable_code' => $s->cableLine->cable_code,
                    'line_name' => $s->cableLine->name,
                    'organization' => $s->cableLine->organization?->short_name ?? $s->cableLine->organization?->name,
                    'gas_pipeline' => $s->cableLine->gasPipelineAsset?->name,
                    'laid_year' => $s->cableLine->laidYearLabel(),
                    'location_description' => $s->location_description,
                    'laying_type' => $s->laying_type->value,
                    'technical_condition' => $s->technical_condition->value,
                    'usage_status' => $s->usage_status->value,
                    'risk_level' => $s->risk_level->value,
                    'complexity_flags' => $s->complexity_flags ?? [],
                    'inspection_status' => $s->inspection_status->value,
                    'dismantling_status' => $s->dismantling_status->value,
                    'design_length_m' => $s->design_length_m,
                    'measured_length_m' => $s->measured_length_m,
                    'geometry_length_m' => $s->geometry_length_m,
                    'geometry_source' => $s->geometry_source?->value,
                    'is_demo' => $s->geometry_source === GeometrySource::Demo || $s->cableLine->is_demo,
                ] + ($full ? ['geometry_source_note' => $s->geometry_source_note] : []),
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $properties */
    private function findSegmentForImport(array $properties): ?CableSegment
    {
        if (! empty($properties['segment_id']) && is_numeric($properties['segment_id'])) {
            return CableSegment::query()->find((int) $properties['segment_id']);
        }
        if (! empty($properties['segment_code']) && is_string($properties['segment_code'])) {
            return CableSegment::query()->where('code', $properties['segment_code'])->first();
        }

        return null;
    }
}
