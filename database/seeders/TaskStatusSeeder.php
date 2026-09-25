<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\TaskStatus;
use Illuminate\Database\Seeder;

class TaskStatusSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', 'workpilot-demo')->firstOrFail();

        $statuses = [
            [
                'name' => 'Non débuté',
                'slug' => 'not-started',
                'color' => '#94A3B8',
                'position' => 1,
                'is_default' => true,
                'is_closed' => false,
            ],
            [
                'name' => 'Planifié',
                'slug' => 'planned',
                'color' => '#3B82F6',
                'position' => 2,
                'is_default' => false,
                'is_closed' => false,
            ],
            [
                'name' => 'En cours',
                'slug' => 'in-progress',
                'color' => '#F59E0B',
                'position' => 3,
                'is_default' => false,
                'is_closed' => false,
            ],
            [
                'name' => 'En attente',
                'slug' => 'waiting',
                'color' => '#8B5CF6',
                'position' => 4,
                'is_default' => false,
                'is_closed' => false,
            ],
            [
                'name' => 'Bloqué',
                'slug' => 'blocked',
                'color' => '#EF4444',
                'position' => 5,
                'is_default' => false,
                'is_closed' => false,
            ],
            [
                'name' => 'À valider',
                'slug' => 'to-review',
                'color' => '#06B6D4',
                'position' => 6,
                'is_default' => false,
                'is_closed' => false,
            ],
            [
                'name' => 'Finalisé',
                'slug' => 'completed',
                'color' => '#22C55E',
                'position' => 7,
                'is_default' => false,
                'is_closed' => true,
            ],
            [
                'name' => 'Annulé',
                'slug' => 'cancelled',
                'color' => '#64748B',
                'position' => 8,
                'is_default' => false,
                'is_closed' => true,
            ],
        ];

        foreach ($statuses as $status) {
            TaskStatus::updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'slug' => $status['slug'],
                ],
                [
                    ...$status,
                    'is_active' => true,
                ]
            );
        }
    }
}