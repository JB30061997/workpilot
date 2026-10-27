<?php

namespace App\Services;

use App\Models\ActionPlan;
use App\Models\Organization;
use App\Models\PlanningYear;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ActionPlanYearRolloverService
{
    public function __construct(private readonly UniqueCodeGenerator $codeGenerator) {}

    /** @return array{years_created: int, plans_created: int, projects_moved: int} */
    public function rollover(?Carbon $date = null, ?int $organizationId = null): array
    {
        $date ??= now();
        $result = ['years_created' => 0, 'plans_created' => 0, 'projects_moved' => 0];

        Organization::query()
            ->where('is_active', true)
            ->when($organizationId, fn ($query) => $query->whereKey($organizationId))
            ->orderBy('id')
            ->each(function (Organization $organization) use ($date, &$result): void {
                $targetYearExisted = $organization->planningYears()
                    ->whereDate('start_date', PlanningYear::boundsFor($date)['start_date'])
                    ->exists();
                $targetYear = PlanningYear::resolveForOrganization($organization->id, $date);
                $previousYear = PlanningYear::resolveForOrganization(
                    $organization->id,
                    $targetYear->start_date->copy()->subDay()
                );

                if (! $targetYearExisted) {
                    $result['years_created']++;
                }

                ActionPlan::query()
                    ->where('organization_id', $organization->id)
                    ->where('planning_year_id', $previousYear->id)
                    ->whereHas('projects', fn ($query) => $query
                        ->whereIn('status', ['draft', 'planned'])
                        ->where('progress', 0))
                    ->orderBy('id')
                    ->each(function (ActionPlan $sourcePlan) use ($targetYear, &$result): void {
                        DB::transaction(function () use ($sourcePlan, $targetYear, &$result): void {
                            $projects = Project::query()
                                ->where('action_plan_id', $sourcePlan->id)
                                ->whereIn('status', ['draft', 'planned'])
                                ->where('progress', 0)
                                ->lockForUpdate()
                                ->get();

                            if ($projects->isEmpty()) {
                                return;
                            }

                            $targetPlan = ActionPlan::query()->firstOrCreate(
                                [
                                    'planning_year_id' => $targetYear->id,
                                    'rollover_source_id' => $sourcePlan->id,
                                ],
                                [
                                    'organization_id' => $sourcePlan->organization_id,
                                    'department_id' => $sourcePlan->department_id,
                                    'created_by' => $sourcePlan->created_by,
                                    'name' => $sourcePlan->name,
                                    'code' => $this->codeGenerator->forActionPlan(
                                        $sourcePlan->name.' '.$targetYear->label,
                                        $sourcePlan->organization_id
                                    ),
                                    'description' => $sourcePlan->description,
                                    'start_date' => $targetYear->start_date,
                                    'end_date' => $targetYear->end_date,
                                    'status' => 'active',
                                    'progress' => 0,
                                    'is_active' => true,
                                ]
                            );

                            if ($targetPlan->wasRecentlyCreated) {
                                $result['plans_created']++;
                            }

                            $axisMap = [];
                            $sourcePlan->axes()->orderBy('position')->each(
                                function ($sourceAxis) use ($targetPlan, &$axisMap): void {
                                    $targetAxis = $targetPlan->axes()->firstOrCreate(
                                        [
                                            'name' => $sourceAxis->name,
                                            'position' => $sourceAxis->position,
                                        ],
                                        [
                                            'code' => $sourceAxis->code,
                                            'description' => $sourceAxis->description,
                                            'position' => $sourceAxis->position,
                                            'is_active' => $sourceAxis->is_active,
                                        ]
                                    );
                                    $axisMap[$sourceAxis->id] = $targetAxis->id;
                                }
                            );

                            foreach ($projects as $project) {
                                $targetAxisId = $project->axis_id
                                    ? ($axisMap[$project->axis_id] ?? null)
                                    : null;

                                $project->actions()->update([
                                    'action_plan_id' => $targetPlan->id,
                                    'axis_id' => $targetAxisId,
                                ]);
                                $project->update([
                                    'action_plan_id' => $targetPlan->id,
                                    'axis_id' => $targetAxisId,
                                ]);
                                $result['projects_moved']++;
                            }
                        });
                    });
            });

        return $result;
    }
}
