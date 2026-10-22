<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Gap-free yearly numbering, e.g. KL-2026-00001. Safe under concurrency (row lock). */
final class SequenceService
{
    public function next(string $prefix, int $padding = 5, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        $value = DB::transaction(function () use ($prefix, $year): int {
            DB::table('sequences')->insertOrIgnore([
                'name' => $prefix, 'year' => $year, 'value' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $row = DB::table('sequences')->where('name', $prefix)->where('year', $year)->lockForUpdate()->first();
            $next = (int) $row->value + 1;
            DB::table('sequences')->where('id', $row->id)->update(['value' => $next, 'updated_at' => now()]);

            return $next;
        });

        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $value, $padding, '0', STR_PAD_LEFT));
    }
}
