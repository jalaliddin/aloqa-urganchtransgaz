<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Bootstraps the head organisation and the first super administrator (production). */
class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin
        {email : Administrator e-mail}
        {--name=Administrator : Full name}
        {--org-code=URTG : Head organisation code}
        {--org-name="Urganchtransgaz" MCHJ : Head organisation name}';

    protected $description = 'Bosh tashkilot va Super Admin foydalanuvchisini yaratadi';

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => 'Database\\Seeders\\RolesAndPermissionsSeeder', '--force' => true]);

        $password = $this->secret('Parol (kamida 10 belgi, harf va raqam)');
        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $password],
            ['email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $organization = Organization::query()->firstOrCreate(
            ['code' => $this->option('org-code')],
            ['type' => OrganizationType::Head, 'name' => $this->option('org-name'), 'short_name' => $this->option('org-name')],
        );

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => $this->option('name'),
            'email' => $this->argument('email'),
            'password' => $password,
            'is_active' => true,
        ]);
        $user->assignRole(Rbac::SUPER_ADMIN);

        $this->info("Super Admin yaratildi: {$user->email}");

        return self::SUCCESS;
    }
}
