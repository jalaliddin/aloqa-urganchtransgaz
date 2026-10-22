<?php

declare(strict_types=1);

namespace App\Enums;

enum ComplexityFlag: string
{
    use HasOptions;

    case NearBuilding = 'near_building';
    case UnderBuilding = 'under_building';
    case UnderRoad = 'under_road';
    case Deep = 'deep';
    case UtilityCrossing = 'utility_crossing';
    case NearGasPipeline = 'near_gas_pipeline';
    case UnknownLocation = 'unknown_location';
}
