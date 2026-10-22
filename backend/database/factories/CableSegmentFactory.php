<?php

namespace Database\Factories;

use App\Enums\LayingType;
use App\Models\CableLine;
use App\Models\CableSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CableSegment>
 */
class CableSegmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cable_line_id' => CableLine::factory(),
            'organization_id' => fn (array $a) => CableLine::query()->find($a['cable_line_id'])->organization_id,
            'sequence' => fake()->unique()->numberBetween(1, 30000),
            'code' => fn (array $a) => 'SEG-'.fake()->unique()->numerify('######'),
            'laying_type' => LayingType::Underground,
            'design_length_m' => '500.00',
        ];
    }
}
