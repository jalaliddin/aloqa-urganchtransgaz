<?php

declare(strict_types=1);

namespace App\Exports;

use App\Reports\Report;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ReportExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        private readonly Report $report,
        private readonly array $columns,
        private readonly array $rows,
    ) {}

    public function array(): array
    {
        return array_map(
            fn (array $row) => array_map(
                fn ($c) => ($row['__totals'] ?? false) ? ($row[$c['key']] ?? '') : $this->report->exportValue($row[$c['key']] ?? null, $c['type']),
                $this->columns,
            ),
            $this->rows,
        );
    }

    public function headings(): array
    {
        return array_column($this->columns, 'label');
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }

    public function title(): string
    {
        // Sheet titles may not contain : \\ / ? * [ ] and are limited to 31 characters
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $this->report->title()), 0, 31);
    }
}
