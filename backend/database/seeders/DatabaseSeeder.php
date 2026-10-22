<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Demo data never reaches production by accident
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
