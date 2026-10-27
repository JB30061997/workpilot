<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionPlan;
use App\Models\Axis;
use App\Models\Department;
use App\Models\Organization;
use App\Models\PlanningYear;
use App\Models\Project;
use App\Models\User;
use App\Services\ActionPlanYearRolloverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActionPlanYearRolloverTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollover_creates_the_new_year_and_moves_only_non_started_projects(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $department = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Informatique',
            'code' => 'IT',
            'is_active' => true,
        ]);
        $previousYear = PlanningYear::query()->create([
            'organization_id' => $organization->id,
            'label' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
        ]);
        $sourcePlan = ActionPlan::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'planning_year_id' => $previousYear->id,
            'created_by' => $user->id,
            'name' => 'Plan infrastructure',
            'code' => 'PLAN-INFRA-26-27',
            'status' => 'active',
            'progress' => 20,
            'is_active' => true,
        ]);
        $sourceAxis = Axis::query()->create([
            'action_plan_id' => $sourcePlan->id,
            'name' => 'Réseau',
            'code' => 'RESEAU',
            'position' => 1,
            'is_active' => true,
        ]);
        $plannedProject = $this->createProject($sourcePlan, $sourceAxis, $user, 'À planifier', 'planned', 0);
        $draftProject = $this->createProject($sourcePlan, $sourceAxis, $user, 'Brouillon', 'draft', 0);
        $startedProject = $this->createProject($sourcePlan, $sourceAxis, $user, 'Déjà lancé', 'in_progress', 30);
        $action = Action::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $plannedProject->id,
            'action_plan_id' => $sourcePlan->id,
            'axis_id' => $sourceAxis->id,
            'created_by' => $user->id,
            'title' => 'Préparer le réseau',
            'status' => 'not_started',
            'priority' => 'medium',
            'progress' => 0,
            'position' => 1,
        ]);

        $firstRun = app(ActionPlanYearRolloverService::class)->rollover(
            Carbon::parse('2027-09-01'),
            $organization->id
        );

        $targetYear = PlanningYear::query()
            ->where('organization_id', $organization->id)
            ->where('label', '2027-2028')
            ->firstOrFail();
        $targetPlan = ActionPlan::query()
            ->where('planning_year_id', $targetYear->id)
            ->where('rollover_source_id', $sourcePlan->id)
            ->firstOrFail();
        $targetAxis = $targetPlan->axes()->where('name', 'Réseau')->firstOrFail();

        $this->assertSame(['years_created' => 1, 'plans_created' => 1, 'projects_moved' => 2], $firstRun);
        $this->assertSame('2027-09-01', $targetYear->start_date->toDateString());
        $this->assertSame('2028-08-31', $targetYear->end_date->toDateString());
        $this->assertSame($targetPlan->id, $plannedProject->fresh()->action_plan_id);
        $this->assertSame($targetAxis->id, $plannedProject->fresh()->axis_id);
        $this->assertSame($targetPlan->id, $draftProject->fresh()->action_plan_id);
        $this->assertSame($sourcePlan->id, $startedProject->fresh()->action_plan_id);
        $this->assertSame($targetPlan->id, $action->fresh()->action_plan_id);
        $this->assertSame($targetAxis->id, $action->fresh()->axis_id);

        $secondRun = app(ActionPlanYearRolloverService::class)->rollover(
            Carbon::parse('2027-09-01'),
            $organization->id
        );

        $this->assertSame(['years_created' => 0, 'plans_created' => 0, 'projects_moved' => 0], $secondRun);
        $this->assertSame(1, ActionPlan::query()->where('rollover_source_id', $sourcePlan->id)->count());
    }

    private function createProject(
        ActionPlan $plan,
        Axis $axis,
        User $creator,
        string $name,
        string $status,
        int $progress
    ): Project {
        return Project::query()->create([
            'organization_id' => $plan->organization_id,
            'department_id' => $plan->department_id,
            'action_plan_id' => $plan->id,
            'axis_id' => $axis->id,
            'created_by' => $creator->id,
            'name' => $name,
            'status' => $status,
            'priority' => 'medium',
            'progress' => $progress,
        ]);
    }
}
