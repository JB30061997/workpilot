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

        /*
        |--------------------------------------------------------------------------
        | PLAN 1 : Infrastructure
        |--------------------------------------------------------------------------
        */

        $infrastructurePlan = ActionPlan::updateOrCreate(
            [
                'organization_id' => $organization->id,
                'code' => 'PA-IT-INFRA-2026',
            ],
            [
                'department_id' => $department->id,
                'created_by' => $creator->id,

                'name' => 'Infrastructure IT',

                'description' =>
                    'Plan d’action dédié à l’infrastructure informatique, au réseau, aux serveurs, au parc informatique et à la sécurité technique.',

                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',

                'status' => 'active',
                'progress' => 45,
                'is_active' => true,
            ]
        );

        $infrastructureAxes = [
            [
                'code' => 'INFRA-RESEAU',
                'name' => 'Réseau & Connectivité',
                'description' => 'Réseau local, Internet, Wi-Fi, VLAN et connectivité.',
                'position' => 1,
            ],
            [
                'code' => 'INFRA-SERV',
                'name' => 'Serveurs & Systèmes',
                'description' => 'Serveurs, systèmes, virtualisation et services internes.',
                'position' => 2,
            ],
            [
                'code' => 'INFRA-PARC',
                'name' => 'Parc informatique',
                'description' => 'Ordinateurs, équipements, renouvellement et inventaire.',
                'position' => 3,
            ],
            [
                'code' => 'INFRA-SEC',
                'name' => 'Sécurité Infrastructure',
                'description' => 'Sécurisation des équipements, réseaux et infrastructures.',
                'position' => 4,
            ],
        ];

        foreach ($infrastructureAxes as $axis) {
            Axis::updateOrCreate(
                [
                    'action_plan_id' => $infrastructurePlan->id,
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

        /*
        |--------------------------------------------------------------------------
        | PLAN 2 : Solutions Digitales
        |--------------------------------------------------------------------------
        */

        $digitalPlan = ActionPlan::updateOrCreate(
            [
                'organization_id' => $organization->id,
                'code' => 'PA-IT-DIGITAL-2026',
            ],
            [
                'department_id' => $department->id,
                'created_by' => $creator->id,

                'name' => 'Solutions Digitales',

                'description' =>
                    'Plan d’action dédié aux applications métiers, à la digitalisation des processus et aux intégrations.',

                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',

                'status' => 'active',
                'progress' => 35,
                'is_active' => true,
            ]
        );

        $digitalAxes = [
            [
                'code' => 'DIG-APPS',
                'name' => 'Applications métiers',
                'description' => 'Développement, évolution et maintenance des applications métiers.',
                'position' => 1,
            ],
            [
                'code' => 'DIG-ERP',
                'name' => 'ERP & Solutions de gestion',
                'description' => 'ERP, Odoo et autres solutions de gestion.',
                'position' => 2,
            ],
            [
                'code' => 'DIG-PROCESS',
                'name' => 'Digitalisation des processus',
                'description' => 'Automatisation et digitalisation des processus internes.',
                'position' => 3,
            ],
            [
                'code' => 'DIG-INT',
                'name' => 'Intégrations & API',
                'description' => 'Intégrations entre applications, API et échanges de données.',
                'position' => 4,
            ],
        ];

        foreach ($digitalAxes as $axis) {
            Axis::updateOrCreate(
                [
                    'action_plan_id' => $digitalPlan->id,
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