<?php

namespace Database\Factories;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = 'TEST '.fake()->unique()->city().' MGQB';

        return [
            'type' => OrganizationType::Mgqb,
            'code' => strtoupper(fake()->unique()->bothify('T-???-###')),
            'name' => $name,
            'short_name' => $name,
            'is_active' => true,
            'is_demo' => true,
        ];
    }

    public function head(): static
    {
        return $this->state(['type' => OrganizationType::Head, 'name' => 'TEST Boshqaruv', 'short_name' => 'TEST Boshqaruv']);
    }
}
