<?php

namespace Database\Seeders;

use App\Models\ActionPlan;
use App\Models\Axis;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Organization
        $organization = Organization::where(
            'slug',
            'workpilot-demo'
        )->firstOrFail();

        // Département IT
        $department = Department::where(
            'organization_id',
            $organization->id
        )
            ->where('code', 'IT')
            ->firstOrFail();

        // Chef de projet
        $projectManager = User::where(
            'email',
            'project.manager@workpilot.local'
        )->firstOrFail();

        // Collaborateur
        $collaborator = User::where(
            'email',
            'collaborator@workpilot.local'
        )->firstOrFail();

        // Plan d'action
        $actionPlan = ActionPlan::where(
            'organization_id',
            $organization->id
        )
            ->where('code', 'PA-2026-IT')
            ->firstOrFail();

        // Axe Applications & Digitalisation
        $axis = Axis::where(
            'action_plan_id',
            $actionPlan->id
        )
            ->where('code', 'AX-APPS')
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Project
        |--------------------------------------------------------------------------
        */

        $project = Project::updateOrCreate(
            [
                'organization_id' => $organization->id,
                'code' => 'PRJ-WORKPILOT-001',
            ],
            [
                'department_id' => $department->id,
                'action_plan_id' => $actionPlan->id,
                'axis_id' => $axis->id,

                'manager_id' => $projectManager->id,
                'created_by' => $projectManager->id,

                'name' => 'Déploiement WorkPilot',

                'description' =>
                    'Projet de démonstration pour le déploiement et le suivi de la plateforme WorkPilot.',

                'start_date' => '2026-09-01',
                'due_date' => '2026-12-31',

                'status' => 'in_progress',
                'priority' => 'high',

                'progress' => 30,

                'is_private' => false,
                'is_active' => true,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Project Members
        |--------------------------------------------------------------------------
        */

        $project->members()->syncWithoutDetaching([

            $projectManager->id => [
                'role' => 'manager',
                'joined_at' => now(),
            ],

            $collaborator->id => [
                'role' => 'member',
                'joined_at' => now(),
            ],

        ]);
    }
}