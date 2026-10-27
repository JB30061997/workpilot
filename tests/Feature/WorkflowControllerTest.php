<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkflowControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_manager_can_create_and_design_a_workflow(): void
    {
        [$user, $project, $session] = $this->projectContext();

        $response = $this->actingAs($user)->withSession($session)->post(route('workflows.store', $project), [
            'name' => 'Validation des livrables',
            'description' => 'Circuit de validation du projet',
        ]);

        $workflow = WorkflowDefinition::query()->firstOrFail();
        $response->assertRedirect(route('workflows.edit', [$project, $workflow]));
        $this->assertDatabaseCount('workflow_nodes', 2);

        $this->actingAs($user)->withSession($session)->put(route('workflows.update', [$project, $workflow]), [
            'name' => 'Validation des livrables',
            'description' => 'Circuit final',
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'label' => 'Début', 'position' => ['x' => 100, 'y' => 100], 'data' => []],
                ['id' => 'task', 'type' => 'task', 'label' => 'Analyser', 'position' => ['x' => 400, 'y' => 100], 'data' => ['duration' => 2]],
                ['id' => 'end', 'type' => 'end', 'label' => 'Fin', 'position' => ['x' => 700, 'y' => 100], 'data' => []],
            ],
            'edges' => [
                ['id' => 'edge-1', 'source' => 'start', 'target' => 'task', 'label' => null],
                ['id' => 'edge-2', 'source' => 'task', 'target' => 'end', 'label' => null],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('workflow_nodes', ['workflow_definition_id' => $workflow->id, 'node_key' => 'task']);
        $this->assertDatabaseCount('workflow_edges', 2);
    }

    public function test_workflow_cannot_be_published_until_every_block_is_reachable(): void
    {
        [$user, $project, $session] = $this->projectContext();
        $workflow = WorkflowDefinition::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'created_by' => $user->id,
            'name' => 'Processus incomplet',
        ]);
        $workflow->nodes()->createMany([
            ['node_key' => 'start', 'type' => 'start', 'label' => 'Début', 'position_x' => 0, 'position_y' => 0],
            ['node_key' => 'end', 'type' => 'end', 'label' => 'Fin', 'position_x' => 300, 'position_y' => 0],
        ]);

        $this->actingAs($user)->withSession($session)
            ->post(route('workflows.publish', [$project, $workflow]))
            ->assertSessionHasErrors('workflow');

        $workflow->edges()->create(['edge_key' => 'edge-1', 'source_key' => 'start', 'target_key' => 'end']);
        $this->actingAs($user)->withSession($session)
            ->post(route('workflows.publish', [$project, $workflow]))
            ->assertSessionHasNoErrors();
        $this->assertSame('active', $workflow->fresh()->status);
    }

    public function test_workflow_library_lists_and_filters_the_current_department_workflows(): void
    {
        [$user, $project, $session] = $this->projectContext();
        WorkflowDefinition::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'created_by' => $user->id,
            'name' => 'Validation achat',
            'status' => 'active',
        ]);
        WorkflowDefinition::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'created_by' => $user->id,
            'name' => 'Circuit support',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($user)->withSession($session)
            ->get(route('workflows.library', ['status' => 'active', 'search' => 'achat']));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Workflows/Library')
            ->has('workflows.data', 1)
            ->where('workflows.data.0.name', 'Validation achat')
            ->where('summary.total', 2)
            ->where('filters.status', 'active')
            ->has('projects', 1)
            ->has('creators', 1));
    }

    public function test_director_general_can_open_workflows_for_an_unassigned_department(): void
    {
        $director = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'WorkPilot', 'slug' => 'workpilot-dg']);
        $assignedDepartment = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Direction',
        ]);
        $accountingDepartment = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Comptabilité',
        ]);
        $director->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $director->departments()->attach($assignedDepartment, ['is_primary' => true, 'is_active' => true]);
        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'DG — Directeur Général',
            'slug' => 'director',
            'is_active' => true,
        ]);
        $director->roles()->attach($role, ['organization_id' => $organization->id]);
        $project = Project::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $accountingDepartment->id,
            'created_by' => $director->id,
            'name' => 'Clôture comptable',
            'code' => 'CLOTURE-COMPTABLE',
        ]);
        $workflow = WorkflowDefinition::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $director->id,
            'name' => 'Validation comptable',
            'status' => 'active',
        ]);
        $session = [
            'current_organization_id' => $organization->id,
            'current_department_id' => $accountingDepartment->id,
        ];

        $this->actingAs($director)
            ->withSession($session)
            ->get(route('workflows.library'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('workflows.data', 1)
                ->where('workflows.data.0.id', $workflow->id));

        $this->actingAs($director)
            ->withSession($session)
            ->get(route('workflows.edit', [$project, $workflow]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Workflows/Builder')
                ->where('workflow.id', $workflow->id)
                ->where('canManage', false));
    }

    /** @return array{User, Project, array<string, int>} */
    private function projectContext(): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'WorkPilot', 'slug' => 'workpilot']);
        $department = Department::query()->create(['organization_id' => $organization->id, 'name' => 'IT']);
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_primary' => true, 'is_active' => true]);
        $project = Project::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'manager_id' => $user->id,
            'created_by' => $user->id,
            'name' => 'Projet pilote',
            'code' => 'PILOTE-01',
        ]);

        return [$user, $project, ['current_organization_id' => $organization->id, 'current_department_id' => $department->id]];
    }
}
