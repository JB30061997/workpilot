<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', 'workpilot-demo')->firstOrFail();

        $departments = [
            ['name' => 'Direction', 'code' => 'DIR'],
            ['name' => 'Informatique', 'code' => 'IT'],
            ['name' => 'Finance', 'code' => 'FIN'],
            ['name' => 'Achats', 'code' => 'ACH'],
            ['name' => 'Comptabilité', 'code' => 'CPT'],
            ['name' => 'Ressources Humaines', 'code' => 'RH'],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'name' => $department['name'],
                ],
                [
                    'code' => $department['code'],
                    'is_active' => true,
                ]
            );
        }
    }
}