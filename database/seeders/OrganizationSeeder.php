<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        Organization::updateOrCreate(
            ['slug' => 'workpilot-demo'],
            [
                'name' => 'WorkPilot Demo',
                'legal_name' => 'WorkPilot Demo',
                'email' => 'contact@workpilot.local',
                'phone' => null,
                'country' => 'Maroc',
                'city' => 'Casablanca',
                'timezone' => 'Africa/Casablanca',
                'locale' => 'fr',
                'is_active' => true,
            ]
        );
    }
}