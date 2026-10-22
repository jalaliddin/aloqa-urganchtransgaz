<?php

declare(strict_types=1);

namespace App\Enums;

enum DismantlingEventType: string
{
    use HasOptions;

    case StatusChange = 'status_change';
    case Progress = 'progress';
    case Incident = 'incident';
    case Nonconformity = 'nonconformity';
    case Note = 'note';
}
