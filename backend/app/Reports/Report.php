<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\User;
use App\Support\Decimal;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A tabular report. Rows are always restricted to the user's organisation scope;
 * the same definition drives the on-screen preview and the Excel/CSV export.
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function title(): string;

    /**
     * Column definitions. Types: text, number, length (metres), money, percent, date, bool, enum:<enum_key>,
     * mass (kg) and kg_per_m — for both, null is shown as "noma'lum", never as 0.
     *
     * @return list<array{key: string, label: string, type: string}>
     */
    abstract public function columns(User $user): array;

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>|Collection<int, array<string, mixed>>
     */
    abstract public function source(User $user, array $filters): Builder|Collection;

    /** @return array<string, mixed> */
    abstract public function mapRow(mixed $row, User $user): array;

    /**
     * Columns summed into a grand-total row over all rows (not just the current page).
     *
     * @return list<string>
     */
    public function totalColumns(): array
    {
        return [];
    }

    /**
     * Sums the given columns; a null cell makes that column's total incomplete (the known part is still summed).
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @return array{values: array<string, ?string>, incomplete: list<string>}
     */
    public function sumColumns(iterable $rows, array $columns, int $scale = 3): array
    {
        $values = array_fill_keys($columns, []);
        $incomplete = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                if (($row[$column] ?? null) === null) {
                    $incomplete[$column] = true;
                } else {
                    $values[$column][] = $row[$column];
                }
            }
        }

        return [
            'values' => array_map(fn ($list) => Decimal::sum($list, $scale), $values),
            'incomplete' => array_keys($incomplete),
        ];
    }

    /** Filters supported in addition to organisation and date range. */
    public function statusFilter(): ?string
    {
        return null;
    }

    public function dateLabel(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function describe(User $user): array
    {
        return [
            'key' => $this->key(),
            'title' => $this->title(),
            'columns' => $this->columns($user),
            'status_filter' => $this->statusFilter(),
            'date_label' => $this->dateLabel(),
        ];
    }

    /** Value formatted for export (labels instead of codes). */
    public function exportValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return in_array($type, ['mass', 'kg_per_m'], true) ? "noma'lum" : '';
        }

        return match (true) {
            $type === 'mass' && $value === null => "noma'lum",
            str_starts_with($type, 'enum:') => $this->enumLabel(substr($type, 5), $value),
            $type === 'bool' => $value ? 'Ha' : "Yo'q",
            in_array($type, ['length', 'money', 'number', 'percent', 'mass', 'kg_per_m'], true) => is_numeric($value) ? (float) $value : $value,
            default => $value instanceof BackedEnum ? $value->value : $value,
        };
    }

    /** @param array<string, mixed> $filters */
    protected function applyDateRange(Builder $query, string $column, array $filters): Builder
    {
        return $query
            ->when($filters['date_from'] ?? null, fn ($q, $from) => $q->where($column, '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($q, $to) => $q->where($column, '<=', Carbon::parse($to)->endOfDay()));
    }

    /** @param array<string, mixed> $filters */
    protected function applyOrganization(Builder $query, array $filters, string $column = 'organization_id'): Builder
    {
        return $query->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where($column, $id));
    }

    private function enumLabel(string $key, mixed $value): string
    {
        $value = $value instanceof BackedEnum ? $value->value : (string) $value;
        $translated = __("enums.{$key}.{$value}");

        return is_string($translated) ? $translated : $value;
    }
}
