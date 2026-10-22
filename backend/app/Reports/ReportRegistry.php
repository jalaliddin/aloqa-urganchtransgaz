<?php

declare(strict_types=1);

namespace App\Reports;

final class ReportRegistry
{
    /** @var list<class-string<Report>> */
    private const REPORTS = [
        OrganizationSummaryReport::class,
        RegistryReport::class,
        InventoryResultsReport::class,
        DismantlingPlanFactReport::class,
        MaterialsReport::class,
        MetalContentReport::class,
        MetalBalanceReport::class,
        IncompleteDocumentsReport::class,
        HazardousSegmentsReport::class,
        LeftInPlaceReport::class,
    ];

    /** @return list<Report> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::REPORTS);
    }

    public function find(string $key): ?Report
    {
        foreach ($this->all() as $report) {
            if ($report->key() === $key) {
                return $report;
            }
        }

        return null;
    }
}
