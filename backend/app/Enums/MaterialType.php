<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialType: string
{
    use HasOptions;

    case CableWhole = 'cable_whole';
    case CablePiece = 'cable_piece';
    case Aluminium = 'aluminium';
    case Lead = 'lead';
    case Copper = 'copper';
    case ScrapMetal = 'scrap_metal';
    case Other = 'other';

    /** The non-ferrous metal this material is, if any. */
    public function metal(): ?Metal
    {
        return match ($this) {
            self::Aluminium => Metal::Aluminium,
            self::Lead => Metal::Lead,
            self::Copper => Metal::Copper,
            default => null,
        };
    }
}
