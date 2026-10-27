<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\PlanningYear;
use Illuminate\Database\Seeder;

class PlanningYearSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Organization::query()->each(function (Organization $organization): void {
            PlanningYear::resolveForOrganization($organization->id, now());
        });
    }
}
