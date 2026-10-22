<?php

namespace Database\Factories;

use App\Models\GasPipelineAsset;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GasPipelineAsset>
 */
class GasPipelineAssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => strtoupper(fake()->unique()->bothify('TGQ-###')),
            'name' => 'TEST gaz quvuri '.fake()->unique()->numberBetween(1, 9999),
            'diameter_mm' => fake()->randomElement([530, 720, 1020, 1220]),
            'is_active' => true,
            'is_demo' => true,
        ];
    }
}
