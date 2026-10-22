<?php

declare(strict_types=1);

namespace App\Enums;

enum CompositionSourceType: string
{
    use HasOptions;

    case CablePassport = 'cable_passport';
    case BrandNormative = 'brand_normative';
    case SupportingDocument = 'supporting_document';
    case Measurement = 'measurement';
}
