<?php

declare(strict_types=1);

namespace App\Enums;

/** Why a theoretical-mass snapshot was stored. */
enum MetalCalculationTrigger: string
{
    use HasOptions;

    case CompositionApproved = 'composition_approved';
    case LengthChanged = 'length_changed';
    case ActRecorded = 'act_recorded';
}
