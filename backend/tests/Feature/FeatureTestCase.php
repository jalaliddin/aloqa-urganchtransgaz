<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDomain;
use Tests\TestCase;

abstract class FeatureTestCase extends TestCase
{
    use BuildsDomain, RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }
}
