<?php

namespace Database\Seeders;

use App\Models\ActionPlan;
use App\Models\Axis;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

class ActionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', 'workpilot-demo')
            ->firstOrFail();

        $department = Department::where('organization_id', $organization->id)
            ->where('code', 'IT')
            ->firstOrFail();

        $creator = User::where('email', 'director@workpilot.local')
            ->firstOrFail();

        $actionPlan = ActionPlan::updateOrCreate(
            [
                'organization_id' => $organization->id,
                'code' => 'PA-2026-IT',
            ],
            [
                'department_id' => $department->id,
                'created_by' => $creator->id,
                'name' => 'Plan d’action SI 2026',
                'description' => 'Plan d’action du service Informatique pour organiser les projets, actions et tâches.',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'active',
                'progress' => 35,
                'is_active' => true,
            ]
        );

        $axes = [
            [
                'code' => 'AX-INFRA',
                'name' => 'Infrastructure & Réseau',
                'description' => 'Infrastructure, serveurs, réseau et équipements.',
                'position' => 1,
            ],
            [
                'code' => 'AX-APPS',
                'name' => 'Applications & Digitalisation',
                'description' => 'Applications métiers, développement et transformation digitale.',
                'position' => 2,
            ],
            [
                'code' => 'AX-SUPPORT',
                'name' => 'Support & Qualité de service',
                'description' => 'Support utilisateurs et amélioration de la qualité de service.',
                'position' => 3,
            ],
            [
                'code' => 'AX-SEC',
                'name' => 'Sécurité & Gouvernance',
                'description' => 'Sécurité, droits d’accès, procédures et gouvernance.',
                'position' => 4,
            ],
        ];

        foreach ($axes as $axis) {
            Axis::updateOrCreate(
                [
                    'action_plan_id' => $actionPlan->id,
                    'code' => $axis['code'],
                ],
                [
                    'name' => $axis['name'],
                    'description' => $axis['description'],
                    'position' => $axis['position'],
                    'is_active' => true,
                ]
            );
        }
    }
}