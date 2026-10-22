<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exports\ReportExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Reports\Report;
use App\Reports\ReportRegistry;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(private readonly ReportRegistry $reports) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('reports.view'), 403);

        return response()->json(['data' => array_map(fn (Report $r) => $r->describe($request->user()), $this->reports->all())]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()->can('reports.view'), 403);
        $report = $this->reports->find($key) ?? abort(404);
        $filters = $this->filters($request);
        $perPage = (int) ($filters['per_page'] ?? 25);

        $source = $report->source($request->user(), $filters);
        $paginator = $source instanceof Builder
            ? $source->paginate($perPage)
            : new LengthAwarePaginator($source->forPage((int) $request->input('page', 1), $perPage)->values(), $source->count(), $perPage);

        return response()->json([
            'report' => $report->describe($request->user()),
            'totals' => $this->totals($report, $report->source($request->user(), $filters), $request->user()),
            'data' => collect($paginator->items())->map(fn ($row) => $report->mapRow($row, $request->user()))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function export(Request $request, string $key, AuditLogger $audit): Response
    {
        abort_unless($request->user()->can('reports.export'), 403);
        $report = $this->reports->find($key) ?? abort(404);
        $filters = $this->filters($request);
        $format = $request->validate(['format' => ['required', 'in:xlsx,csv']])['format'];
        $user = $request->user();

        $source = $report->source($user, $filters);
        $rows = collect(($source instanceof Builder ? $source->lazy(500) : $source)->map(fn ($row) => $report->mapRow($row, $user))->all());
        $columns = $report->columns($user);
        $totals = $report->totalColumns() === [] ? null : $report->sumColumns($rows, $report->totalColumns());
        if ($totals !== null) {
            $rows->push($this->totalsRow($columns, $totals));
        }

        $audit->log('report_exported', null, null, ['report' => $key, 'format' => $format, 'filters' => $filters]);
        $fileName = $key.'-'.now()->format('Ymd-His').'.'.$format;

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($rows, $columns, $report): void {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
                fputcsv($out, array_column($columns, 'label'), ';');
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($c) => ($row['__totals'] ?? false) ? ($row[$c['key']] ?? '') : $report->exportValue($row[$c['key']] ?? null, $c['type']), $columns), ';');
                }
                fclose($out);
            }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return Excel::download(new ReportExport($report, $columns, $rows->all()), $fileName);
    }

    /**
     * @return array{values: array<string, ?string>, incomplete: list<string>}|null
     */
    private function totals(Report $report, mixed $source, User $user): ?array
    {
        if ($report->totalColumns() === []) {
            return null;
        }

        $rows = ($source instanceof Builder ? $source->lazy(500) : $source)->map(fn ($row) => $report->mapRow($row, $user));

        return $report->sumColumns($rows, $report->totalColumns());
    }

    /**
     * "Jami" row for exports; incomplete totals are labelled so a partial sum is never read as complete.
     *
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  array{values: array<string, ?string>, incomplete: list<string>}  $totals
     * @return array<string, mixed>
     */
    private function totalsRow(array $columns, array $totals): array
    {
        $row = ['__totals' => true, $columns[0]['key'] => 'Jami'];
        foreach ($totals['values'] as $key => $value) {
            $row[$key] = in_array($key, $totals['incomplete'], true) ? "≥ {$value} (to'liq emas)" : (float) $value;
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'organization_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:48'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'only_discrepancies' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
