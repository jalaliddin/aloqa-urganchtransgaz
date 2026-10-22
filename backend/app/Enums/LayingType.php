<?php

declare(strict_types=1);

namespace App\Enums;

enum LayingType: string
{
    use HasOptions;

    case Underground = 'underground';
    case Duct = 'duct';
    case RoadCrossing = 'road_crossing';
    case NearBuilding = 'near_building';
    case Other = 'other';
}
