<?php

declare(strict_types=1);

namespace App\Enums;

enum GeometrySource: string
{
    use HasOptions;

    case Demo = 'demo';
    case Manual = 'manual';
    case Gps = 'gps';
    case Import = 'import';
}
