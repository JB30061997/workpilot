<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Database\Seeder;

class WorkSessionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Organization
        |--------------------------------------------------------------------------
        */

        $organization = Organization::where(
            'slug',
            'workpilot-demo'
        )->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Department
        |--------------------------------------------------------------------------
        */

        $department = Department::where(
            'organization_id',
            $organization->id
        )
            ->where('code', 'IT')
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Project
        |--------------------------------------------------------------------------
        |
        | On prend le premier projet de démonstration du service IT.
        |
        */

        $project = Project::where(
            'organization_id',
            $organization->id
        )
            ->where('department_id', $department->id)
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Users
        |--------------------------------------------------------------------------
        */

        $creator = User::where(
            'email',
            'project.manager@workpilot.local'
        )->firstOrFail();

        $collaborator = User::where(
            'email',
            'collaborator@workpilot.local'
        )->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Work Session
        |--------------------------------------------------------------------------
        |
        | Exemple :
        |
        | Session privée entre Chef de Projet + Collaborateur
        | du lundi 21/09/2026 au vendredi 25/09/2026.
        |
        */

        $session = WorkSession::updateOrCreate(
            [
                'organization_id' => $organization->id,
                'name' => 'Sprint de travail - Septembre 2026',
            ],
            [
                'department_id' => $department->id,
                'project_id' => $project->id,
                'created_by' => $creator->id,

                'description' =>
                    'Session privée de travail pour organiser et planifier les tâches du projet.',

                'start_date' => '2026-09-21',
                'end_date' => '2026-09-25',

                'visibility' => 'private',
                'status' => 'active',

                'is_active' => true,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Session Members
        |--------------------------------------------------------------------------
        |
        | Chef de projet = Owner
        | Collaborateur  = Member
        |
        */

        $session->members()->syncWithoutDetaching([

            $creator->id => [
                'role' => 'owner',
                'joined_at' => now(),
            ],

            $collaborator->id => [
                'role' => 'member',
                'joined_at' => now(),
            ],

        ]);
    }
}