<?php

namespace Database\Seeders;

use App\Models\Task;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Database\Seeder;

class TimeSlotSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Work Session
        |--------------------------------------------------------------------------
        */

        $session = WorkSession::where(
            'name',
            'Sprint de travail - Septembre 2026'
        )->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Users
        |--------------------------------------------------------------------------
        */

        $projectManager = User::where(
            'email',
            'project.manager@workpilot.local'
        )->firstOrFail();

        $collaborator = User::where(
            'email',
            'collaborator@workpilot.local'
        )->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Tasks
        |--------------------------------------------------------------------------
        |
        | Si des tâches existent déjà, on va les utiliser.
        | Sinon les créneaux resteront sans task_id.
        |
        */

        $tasks = Task::where(
            'project_id',
            $session->project_id
        )
            ->orderBy('id')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Planning
        |--------------------------------------------------------------------------
        */

        $planning = [
            [
                'title' => 'Analyse des besoins',
                'description' => 'Analyse des besoins et préparation des travaux à réaliser.',
                'start_at' => '2026-09-21 08:00:00',
                'end_at' => '2026-09-21 10:00:00',
                'status' => 'completed',
                'task_index' => 0,
                'members' => [
                    $projectManager->id,
                    $collaborator->id,
                ],
            ],

            [
                'title' => 'Réunion de coordination',
                'description' => 'Point de coordination entre les membres de la session.',
                'start_at' => '2026-09-21 10:00:00',
                'end_at' => '2026-09-21 11:00:00',
                'status' => 'completed',
                'task_index' => null,
                'members' => [
                    $projectManager->id,
                    $collaborator->id,
                ],
            ],

            [
                'title' => 'Développement',
                'description' => 'Développement des fonctionnalités prévues dans le sprint.',
                'start_at' => '2026-09-21 11:00:00',
                'end_at' => '2026-09-21 13:00:00',
                'status' => 'in_progress',
                'task_index' => 1,
                'members' => [
                    $collaborator->id,
                ],
            ],

            [
                'title' => 'Développement des fonctionnalités',
                'description' => 'Poursuite du développement et intégration.',
                'start_at' => '2026-09-22 08:30:00',
                'end_at' => '2026-09-22 10:30:00',
                'status' => 'planned',
                'task_index' => 1,
                'members' => [
                    $projectManager->id,
                    $collaborator->id,
                ],
            ],

            [
                'title' => 'Tests fonctionnels',
                'description' => 'Tests des fonctionnalités développées.',
                'start_at' => '2026-09-22 11:00:00',
                'end_at' => '2026-09-22 12:30:00',
                'status' => 'planned',
                'task_index' => 2,
                'members' => [
                    $collaborator->id,
                ],
            ],

            [
                'title' => 'Correction des anomalies',
                'description' => 'Traitement des anomalies identifiées pendant les tests.',
                'start_at' => '2026-09-23 09:00:00',
                'end_at' => '2026-09-23 11:00:00',
                'status' => 'planned',
                'task_index' => 2,
                'members' => [
                    $projectManager->id,
                    $collaborator->id,
                ],
            ],

            [
                'title' => 'Revue du sprint',
                'description' => 'Revue de l’avancement et validation des éléments réalisés.',
                'start_at' => '2026-09-24 10:00:00',
                'end_at' => '2026-09-24 11:00:00',
                'status' => 'planned',
                'task_index' => null,
                'members' => [
                    $projectManager->id,
                    $collaborator->id,
                ],
            ],

            [
                'title' => 'Finalisation et validation',
                'description' => 'Finalisation des travaux et validation de la session.',
                'start_at' => '2026-09-25 09:00:00',
                'end_at' => '2026-09-25 11:00:00',
                'status' => 'planned',
                'task_index' => 3,
                'members' => [
                    $projectManager->id,
                    $collaborator->id,
                ],
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Create Time Slots
        |--------------------------------------------------------------------------
        */

        foreach ($planning as $item) {

            $task = null;

            if ($item['task_index'] !== null) {
                $task = $tasks->get($item['task_index']);
            }

            $slot = TimeSlot::updateOrCreate(
                [
                    'work_session_id' => $session->id,
                    'start_at' => $item['start_at'],
                    'end_at' => $item['end_at'],
                ],
                [
                    'task_id' => $task?->id,
                    'created_by' => $projectManager->id,
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'status' => $item['status'],
                    'is_locked' => false,
                ]
            );

            $slot->members()->sync($item['members']);
        }
    }
}