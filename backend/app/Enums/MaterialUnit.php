<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialUnit: string
{
    use HasOptions;

    case M = 'm';
    case Kg = 'kg';
    case Pcs = 'pcs';
}
