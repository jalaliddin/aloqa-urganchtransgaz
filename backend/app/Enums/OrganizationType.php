<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationType: string
{
    use HasOptions;

    case Head = 'head';
    case Mgqb = 'mgqb';
    case Contractor = 'contractor';
    case Other = 'other';
}
