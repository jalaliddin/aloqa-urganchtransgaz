<?php

namespace Database\Factories;

use App\Enums\LaidYearPrecision;
use App\Models\CableLine;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates a bare line row. Prefer CableRegistryService::createLine() in tests that
 * need segments and aggregated statuses.
 *
 * @extends Factory<CableLine>
 */
class CableLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'registry_number' => 'KL-TEST-'.fake()->unique()->numerify('#####'),
            'name' => 'TEST kabel liniyasi '.fake()->unique()->numberBetween(1, 99999),
            'brand' => 'MKSB 4x4x1.2',
            'laid_year' => 1968,
            'laid_year_precision' => LaidYearPrecision::Decade,
            'organization_id' => Organization::factory(),
            'balance_holder_id' => fn (array $attributes) => $attributes['organization_id'],
            'design_length_m' => '1000.00',
            'is_demo' => true,
        ];
    }
}
