<?php

declare(strict_types=1);

namespace App\Enums;

/** Non-ferrous metals tracked in cables. */
enum Metal: string
{
    use HasOptions;

    case Aluminium = 'aluminium';
    case Lead = 'lead';
    case Copper = 'copper';

    /** Column holding kg per metre in cable_metal_compositions. */
    public function compositionColumn(): string
    {
        return $this->value.'_kg_per_m';
    }

    /** Column holding the mass in kg (calculations, dismantling act). */
    public function massColumn(): string
    {
        return $this->value.'_kg';
    }

    public function actColumn(): string
    {
        return 'act_'.$this->value.'_kg';
    }

    /** Warehouse material type recorded for this metal. */
    public function materialType(): MaterialType
    {
        return match ($this) {
            self::Aluminium => MaterialType::Aluminium,
            self::Lead => MaterialType::Lead,
            self::Copper => MaterialType::Copper,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Aluminium => 'gray',
            self::Lead => 'dark',
            self::Copper => 'orange',
        };
    }
}
