<?php

namespace Database\Seeders;

use App\Support\Rbac;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Idempotent: safe to run in production after every deployment. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(Rbac::PERMISSIONS) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (array_keys(Rbac::ROLES) as $roleName) {
            Role::findOrCreate($roleName, 'web')->syncPermissions(Rbac::permissionsFor($roleName));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
