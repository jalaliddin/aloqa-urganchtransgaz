<?php

declare(strict_types=1);

namespace App\Enums;

enum LaidYearPrecision: string
{
    use HasOptions;

    case Exact = 'exact';
    case Decade = 'decade';
    case Approximate = 'approximate';
    case Unknown = 'unknown';
}
