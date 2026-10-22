<?php

declare(strict_types=1);

namespace App\Enums;

enum InspectionMemberRole: string
{
    use HasOptions;

    case Chair = 'chair';
    case Member = 'member';
    case Secretary = 'secretary';
}
