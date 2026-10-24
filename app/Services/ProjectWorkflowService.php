<?php

namespace App\Services;

use App\Models\Action;
use App\Models\Project;
use App\Models\User;

class ProjectWorkflowService
{
    private const DEFAULT_ACTION_TITLES = [
        'Cadrage et Définition des Besoins',
        'Conception Détaillée (Fonctionnelle & Technique)',
        'Installation et Paramétrage / Développement',
        'Tests Unitaires et Intégration',
        'Recette Utilisateur et Validation',
        'Déploiement, Migration et Mise en Production',
        'Support Post-Démarrage et Clôture du Projet',
    ];

    public function __construct(private readonly UniqueCodeGenerator $codeGenerator) {}

    public function createDefaultActions(Project $project, User $creator): void
    {
        $now = now();
        $actions = collect(self::DEFAULT_ACTION_TITLES)->values()
            ->map(fn (string $title, int $index): array => [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'action_plan_id' => $project->action_plan_id,
                'axis_id' => $project->axis_id,
                'owner_id' => null,
                'created_by' => $creator->id,
                'title' => $title,
                'description' => null,
                'start_date' => null,
                'due_date' => null,
                'actual_start_date' => null,
                'actual_end_date' => null,
                'status' => 'not_started',
                'priority' => 'medium',
                'progress' => 0,
                'position' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        Action::query()->insert($actions);
    }

    public function duplicateAction(Action $action, User $creator): Action
    {
        Action::query()->where('project_id', $action->project_id)
            ->where('position', '>', $action->position)->increment('position');

        return Action::query()->create([
            'organization_id' => $action->organization_id,
            'project_id' => $action->project_id,
            'action_plan_id' => $action->action_plan_id,
            'axis_id' => $action->axis_id,
            'owner_id' => null,
            'created_by' => $creator->id,
            'title' => $action->title.' (Copie)',
            'description' => $action->description,
            'start_date' => null,
            'due_date' => null,
            'actual_start_date' => null,
            'actual_end_date' => null,
            'status' => 'not_started',
            'priority' => $action->priority,
            'progress' => 0,
            'position' => $action->position + 1,
        ]);
    }

    public function duplicateProject(Project $project, User $creator): Project
    {
        $project->loadMissing([
            'departments:id',
            'actions' => fn ($query) => $query->orderBy('position')->orderBy('id'),
        ]);

        Project::query()
            ->where('organization_id', $project->organization_id)
            ->where('action_plan_id', $project->action_plan_id)
            ->where('axis_id', $project->axis_id)
            ->where('position', '>', $project->position)
            ->increment('position');

        $copy = Project::query()->create([
            'organization_id' => $project->organization_id,
            'department_id' => $project->department_id,
            'action_plan_id' => $project->action_plan_id,
            'axis_id' => $project->axis_id,
            'project_category_id' => $project->project_category_id,
            'manager_id' => null,
            'created_by' => $creator->id,
            'name' => $project->name.' (Copie)',
            'code' => $project->code
                ? $this->codeGenerator->forProject($project->name.' Copie', $project->organization_id)
                : null,
            'description' => $project->description,
            'start_date' => null,
            'due_date' => null,
            'status' => 'draft',
            'priority' => $project->priority,
            'position' => $project->position + 1,
            'progress' => 0,
            'is_private' => $project->is_private,
            'is_active' => $project->is_active,
        ]);

        $copy->departments()->sync($project->departments->modelKeys());

        $now = now();
        $actions = $project->actions->values()->map(fn (Action $action, int $index): array => [
            'organization_id' => $copy->organization_id,
            'project_id' => $copy->id,
            'action_plan_id' => $copy->action_plan_id,
            'axis_id' => $copy->axis_id,
            'owner_id' => null,
            'created_by' => $creator->id,
            'title' => $action->title,
            'description' => $action->description,
            'start_date' => null,
            'due_date' => null,
            'actual_start_date' => null,
            'actual_end_date' => null,
            'status' => 'not_started',
            'priority' => $action->priority,
            'progress' => 0,
            'position' => $index + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($actions !== []) {
            Action::query()->insert($actions);
        }

        return $copy;
    }
}
