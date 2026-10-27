<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Services\DefaultRoleProvisioner;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(DefaultRoleProvisioner $defaultRoleProvisioner): void
    {
        Organization::query()->each(
            fn (Organization $organization) => $defaultRoleProvisioner->provision($organization, true)
        );
    }
}
