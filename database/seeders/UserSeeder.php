<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', 'workpilot-demo')->firstOrFail();

        $it = Department::where('organization_id', $organization->id)
            ->where('code', 'IT')
            ->firstOrFail();

        $users = [
            [
                'name' => 'Admin WorkPilot',
                'email' => 'admin@workpilot.local',
                'job_title' => 'Administrateur',
                'role' => 'administrator',
                'manager' => true,
            ],

            [
                'name' => 'Directeur',
                'email' => 'director@workpilot.local',
                'job_title' => 'Directeur',
                'role' => 'director',
                'manager' => true,
            ],

            [
                'name' => 'Chef de Projet',
                'email' => 'project.manager@workpilot.local',
                'job_title' => 'Chef de Projet',
                'role' => 'project-manager',
                'manager' => false,
            ],

            [
                'name' => 'Collaborateur',
                'email' => 'collaborator@workpilot.local',
                'job_title' => 'Collaborateur',
                'role' => 'collaborator',
                'manager' => false,
            ],
        ];

        foreach ($users as $data) {

            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );

            $organization->users()->syncWithoutDetaching([
                $user->id => [
                    'job_title' => $data['job_title'],
                    'status' => 'active',
                    'joined_at' => now(),
                    'is_owner' => $data['role'] === 'administrator',
                    'is_active' => true,
                ],
            ]);

            $it->users()->syncWithoutDetaching([
                $user->id => [
                    'is_manager' => $data['manager'],
                    'is_primary' => true,
                    'is_active' => true,
                    'joined_at' => now(),
                ],
            ]);

            $role = Role::where('organization_id', $organization->id)
                ->where('slug', $data['role'])
                ->firstOrFail();

            $user->roles()->syncWithoutDetaching([
                $role->id => [
                    'organization_id' => $organization->id,
                ],
            ]);
        }
    }
}