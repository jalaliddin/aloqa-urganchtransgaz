<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Shared helpers for string-backed enums: translated labels and option lists for the UI.
 * Labels live in lang/{locale}/enums.php under the snake_case enum name.
 */
trait HasOptions
{
    public function label(): string
    {
        return __('enums.'.self::translationKey().'.'.$this->value);
    }

    /** Mantine colour name used consistently for this value across the UI. */
    public function color(): string
    {
        return 'gray';
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string, color: string}> */
    public static function options(): array
    {
        return array_map(static fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'color' => $case->color(),
        ], self::cases());
    }

    public static function translationKey(): string
    {
        return Str::snake(class_basename(self::class));
    }
}
