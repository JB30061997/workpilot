<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionPlan;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_page_only_displays_projects_from_selected_context(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'selected');
        [$otherOrganization, $otherDepartment] = $this->createContext($user, 'other');
        $project = $this->createProject($user, $organization, $department, 'Visible Project');
        $this->createProject($user, $otherOrganization, $otherDepartment, 'Hidden Project');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('projects.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Index')
            ->where('organization.id', $organization->id)
            ->where('department.id', $department->id)
            ->has('projects.data', 1)
            ->where('projects.data.0.id', $project->id)
            ->where('projects.data.0.name', 'Visible Project'));
    }

    public function test_projects_workspace_includes_ordered_actions_from_selected_context(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'workspace');
        [$otherOrganization, $otherDepartment] = $this->createContext($user, 'outside-workspace');
        $project = $this->createProject($user, $organization, $department, 'Workspace Project');
        $otherProject = $this->createProject($user, $otherOrganization, $otherDepartment, 'Hidden Workspace Project');
        Action::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $user->id,
            'title' => 'Second action',
            'status' => 'in_progress',
            'priority' => 'high',
            'progress' => 40,
            'position' => 2,
        ]);
        $firstAction = Action::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $user->id,
            'title' => 'First action',
            'status' => 'completed',
            'priority' => 'medium',
            'progress' => 100,
            'position' => 1,
        ]);
        Action::query()->create([
            'organization_id' => $otherOrganization->id,
            'project_id' => $otherProject->id,
            'created_by' => $user->id,
            'title' => 'Hidden action',
            'status' => 'not_started',
            'priority' => 'medium',
            'progress' => 0,
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('projects.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('projectWorkspace', 1)
            ->where('projectWorkspace.0.id', $project->id)
            ->where('projectWorkspace.0.actions_count', 2)
            ->has('projectWorkspace.0.actions', 2)
            ->where('projectWorkspace.0.actions.0.id', $firstAction->id)
            ->where('projectWorkspace.0.actions.0.progress', 100));
    }

    public function test_portfolio_tabs_separate_solution_and_infrastructure_projects(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'portfolio-tabs');
        $solutionProject = $this->createProject($user, $organization, $department, 'Solution Project');
        $infrastructureProject = $this->createProject(
            $user,
            $organization,
            $department,
            'Infrastructure Project',
            'infrastructure',
        );
        $session = [
            'current_organization_id' => $organization->id,
            'current_department_id' => $department->id,
        ];

        $solutionsResponse = $this
            ->actingAs($user)
            ->withSession($session)
            ->get(route('projects.index', ['portfolio' => 'solutions']));
        $infrastructureResponse = $this
            ->actingAs($user)
            ->withSession($session)
            ->get(route('projects.index', ['portfolio' => 'infrastructure']));

        $solutionsResponse->assertInertia(fn (Assert $page) => $page
            ->where('filters.portfolio', 'solutions')
            ->has('projects.data', 1)
            ->where('projects.data.0.id', $solutionProject->id)
            ->where('portfolioCounts.solutions', 1)
            ->where('portfolioCounts.infrastructure', 1));
        $infrastructureResponse->assertInertia(fn (Assert $page) => $page
            ->where('filters.portfolio', 'infrastructure')
            ->has('projects.data', 1)
            ->where('projects.data.0.id', $infrastructureProject->id));
    }

    public function test_json_clients_keep_receiving_the_projects_payload(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'json');
        $project = $this->createProject($user, $organization, $department, 'API Project');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->getJson(route('projects.index'));

        $response
            ->assertOk()
            ->assertJsonPath('projects.data.0.id', $project->id)
            ->assertJsonPath('projects.data.0.name', 'API Project');
    }

    public function test_valid_payload_creates_project_in_selected_context(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'create');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => 'New Project',
                'status' => 'planned',
                'priority' => 'high',
                'progress' => 25,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('projects', [
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
            'name' => 'New Project',
            'status' => 'planned',
            'priority' => 'high',
            'progress' => 0,
        ]);
        $project = Project::query()->where('name', 'New Project')->firstOrFail();
        $this->assertSame(7, $project->actions()->count());
        $this->assertSame([
            'Cadrage et Définition des Besoins',
            'Conception Détaillée (Fonctionnelle & Technique)',
            'Installation et Paramétrage / Développement',
            'Tests Unitaires et Intégration',
            'Recette Utilisateur et Validation',
            'Déploiement, Migration et Mise en Production',
            'Support Post-Démarrage et Clôture du Projet',
        ], $project->actions()->orderBy('position')->pluck('title')->all());
        $this->assertSame(0, $project->actions()->whereNotNull('start_date')->count());
        $this->assertSame(0, $project->actions()->whereNotNull('due_date')->count());
        $this->assertSame(0, $project->actions()->whereNotNull('owner_id')->count());
        $this->assertSame(7, $project->actions()->where('status', 'not_started')->where('progress', 0)->count());
    }

    public function test_project_duplication_copies_only_actions_and_resets_execution_data(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'duplicate-project');
        $project = $this->createProject($user, $organization, $department, 'Original Project');
        Action::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'action_plan_id' => $project->action_plan_id,
            'owner_id' => $user->id,
            'created_by' => $user->id,
            'title' => 'Executed action',
            'description' => 'Description copied',
            'start_date' => '2026-10-01',
            'due_date' => '2026-10-10',
            'status' => 'completed',
            'priority' => 'high',
            'progress' => 100,
            'position' => 4,
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('projects.duplicate', $project));

        $response->assertSessionHasNoErrors()->assertSessionHas('success');
        $copy = Project::query()->where('name', 'Original Project (Copie)')->firstOrFail();
        $this->assertSame(1, $copy->actions()->count());
        $this->assertDatabaseHas('actions', [
            'project_id' => $copy->id,
            'title' => 'Executed action',
            'description' => 'Description copied',
            'owner_id' => null,
            'start_date' => null,
            'due_date' => null,
            'status' => 'not_started',
            'progress' => 0,
            'position' => 1,
        ]);
    }

    public function test_project_from_another_organization_cannot_be_duplicated(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'duplicate-current');
        [$otherOrganization, $otherDepartment] = $this->createContext($user, 'duplicate-other');
        $project = $this->createProject($user, $otherOrganization, $otherDepartment, 'Hidden Project');

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('projects.duplicate', $project))
            ->assertNotFound();

        $this->assertDatabaseMissing('projects', ['name' => 'Hidden Project (Copie)']);
    }

    public function test_invalid_status_and_priority_are_rejected(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'invalid');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('projects.store'), [
                'name' => 'Invalid Project',
                'status' => 'unknown',
                'priority' => 'urgent',
            ]);

        $response->assertInvalid(['status', 'priority']);
        $this->assertDatabaseMissing('projects', [
            'name' => 'Invalid Project',
        ]);
    }

    public function test_repeated_project_submission_token_creates_only_one_project_and_seven_actions(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'idempotent-project');
        $session = [
            'current_organization_id' => $organization->id,
            'current_department_id' => $department->id,
        ];
        $payload = [
            'creation_token' => '2ce4be7e-5e31-4636-8d18-07e6a47be438',
            'name' => 'Single Project',
            'status' => 'planned',
            'priority' => 'medium',
        ];

        $this->actingAs($user)->withSession($session)->post(route('projects.store'), $payload);
        $this->actingAs($user)->withSession($session)->post(route('projects.store'), $payload);

        $this->assertSame(1, Project::query()->where('name', 'Single Project')->count());
        $project = Project::query()->where('name', 'Single Project')->firstOrFail();
        $this->assertSame(7, $project->actions()->count());
    }

    public function test_project_can_be_updated_with_an_active_manager_from_selected_context(): void
    {
        $user = User::factory()->create();
        $manager = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'update-manager');
        $manager->organizations()->attach($organization, [
            'status' => 'active',
            'is_active' => true,
        ]);
        $manager->departments()->attach($department, [
            'is_active' => true,
        ]);
        $project = $this->createProject($user, $organization, $department, 'Project before update');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('projects.update', $project), [
                'name' => 'Project after update',
                'manager_id' => $manager->id,
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Project after update',
            'manager_id' => $manager->id,
        ]);
    }

    public function test_project_from_another_context_returns_not_found(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'current');
        [$otherOrganization, $otherDepartment] = $this->createContext($user, 'restricted');
        $otherProject = $this->createProject(
            $user,
            $otherOrganization,
            $otherDepartment,
            'Restricted Project',
        );

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->getJson(route('projects.show', $otherProject));

        $response->assertNotFound();
    }

    public function test_assigned_manager_can_update_their_project(): void
    {
        $director = User::factory()->create();
        $manager = User::factory()->create();
        [$organization, $department] = $this->createContext($director, 'manager-update');
        $manager->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $manager->departments()->attach($department, ['is_active' => true]);
        $project = $this->createProject($director, $organization, $department, 'Managed Project');
        $project->update(['manager_id' => $manager->id]);

        $response = $this
            ->actingAs($manager)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('projects.update', $project), ['name' => 'Manager Updated Project']);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Manager Updated Project',
        ]);
    }

    public function test_active_department_member_can_create_but_cannot_update_another_users_project(): void
    {
        $director = User::factory()->create();
        $member = User::factory()->create();
        [$organization, $department] = $this->createContext($director, 'restricted-write');
        $member->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $member->departments()->attach($department, ['is_active' => true]);
        $project = $this->createProject($director, $organization, $department, 'Protected Project');
        $session = [
            'current_organization_id' => $organization->id,
            'current_department_id' => $department->id,
        ];

        $createResponse = $this
            ->actingAs($member)
            ->withSession($session)
            ->post(route('projects.store'), [
                'name' => 'Member Project',
                'status' => 'planned',
                'priority' => 'medium',
            ]);
        $updateResponse = $this
            ->actingAs($member)
            ->withSession($session)
            ->patch(route('projects.update', $project), ['name' => 'Forbidden Update']);

        $createResponse->assertSessionHasNoErrors()->assertRedirect();
        $updateResponse->assertForbidden();
        $this->assertDatabaseHas('projects', [
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $member->id,
            'name' => 'Member Project',
        ]);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Protected Project']);
    }

    public function test_active_department_member_sees_project_creation_controls_and_can_open_the_prefilled_form(): void
    {
        $director = User::factory()->create();
        $member = User::factory()->create();
        [$organization, $department] = $this->createContext($director, 'member-create-controls');
        $member->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $member->departments()->attach($department, ['is_active' => true]);
        $plan = ActionPlan::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $director->id,
            'name' => 'Member Plan',
            'code' => 'PAC-SOLUTIONS-MEMBER',
            'status' => 'active',
            'progress' => 0,
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($member)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('projects.index', [
                'action_plan_id' => $plan->id,
                'create_project' => 1,
            ]));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Index')
            ->where('canCreateProjects', true)
            ->where('openCreateProject', true)
            ->where('filters.action_plan_id', (string) $plan->id));
    }

    public function test_inactive_department_member_cannot_create_a_project(): void
    {
        $director = User::factory()->create();
        $inactiveMember = User::factory()->create();
        [$organization, $department] = $this->createContext($director, 'inactive-member');
        $inactiveMember->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $inactiveMember->departments()->attach($department, ['is_active' => false]);

        $response = $this
            ->actingAs($inactiveMember)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('projects.store'), [
                'name' => 'Inactive Member Project',
                'status' => 'planned',
                'priority' => 'medium',
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('projects', ['name' => 'Inactive Member Project']);
    }

    /**
     * @return array{Organization, Department}
     */
    public function test_project_can_link_multiple_departments_from_the_same_organization(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'collaboration');
        $partnerDepartment = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Partner Department',
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('projects.store'), [
                'name' => 'Cross Department Project',
                'status' => 'planned',
                'priority' => 'high',
                'position' => 1,
                'department_ids' => [$department->id, $partnerDepartment->id],
            ]);

        $response->assertSessionHasNoErrors();

        $project = Project::query()->where('name', 'Cross Department Project')->firstOrFail();
        $this->assertSame(
            [$department->id, $partnerDepartment->id],
            $project->departments()->orderBy('departments.id')->pluck('departments.id')->all(),
        );
        $this->assertSame(1, $project->position);
    }

    public function test_project_rejects_a_department_from_another_organization(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'department-scope');
        [, $outsideDepartment] = $this->createContext($user, 'outside-department-scope');

        $response = $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('projects.store'), [
                'name' => 'Invalid Shared Project',
                'status' => 'planned',
                'priority' => 'medium',
                'position' => 1,
                'department_ids' => [$department->id, $outsideDepartment->id],
            ]);

        $response->assertInvalid(['department_ids']);
        $this->assertDatabaseMissing('projects', ['name' => 'Invalid Shared Project']);
    }

    public function test_projects_can_be_filtered_by_category_and_sorted_by_priority_position(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'category-filter-sort');
        $support = ProjectCategory::query()->create([
            'organization_id' => $organization->id,
            'manager_id' => $user->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);
        $pedagogical = ProjectCategory::query()->create([
            'organization_id' => $organization->id,
            'manager_id' => $user->id,
            'name' => 'Pédagogique',
            'slug' => 'pedagogique',
        ]);
        $secondProject = $this->createProject($user, $organization, $department, 'Second Priority');
        $secondProject->update(['project_category_id' => $support->id, 'position' => 2]);
        $firstProject = $this->createProject($user, $organization, $department, 'First Priority');
        $firstProject->update(['project_category_id' => $pedagogical->id, 'position' => 1]);
        $session = [
            'current_organization_id' => $organization->id,
            'current_department_id' => $department->id,
        ];

        $sortedResponse = $this->actingAs($user)
            ->withSession($session)
            ->get(route('projects.index', [
                'sort' => 'position',
                'direction' => 'asc',
            ]));
        $filteredResponse = $this->actingAs($user)
            ->withSession($session)
            ->get(route('projects.index', [
                'project_category_id' => $support->id,
            ]));

        $sortedResponse->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'position')
            ->where('filters.direction', 'asc')
            ->where('projects.data.0.id', $firstProject->id)
            ->where('projects.data.1.id', $secondProject->id));
        $filteredResponse->assertInertia(fn (Assert $page) => $page
            ->where('filters.project_category_id', (string) $support->id)
            ->has('projects.data', 1)
            ->where('projects.data.0.id', $secondProject->id));
    }

    public function test_active_department_member_can_persist_project_priority_order(): void
    {
        $director = User::factory()->create();
        $member = User::factory()->create();
        [$organization, $department] = $this->createContext($director, 'priority-reorder');
        $member->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $member->departments()->attach($department, ['is_active' => true]);
        $category = ProjectCategory::query()->create([
            'organization_id' => $organization->id,
            'manager_id' => $director->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);
        $otherCategory = ProjectCategory::query()->create([
            'organization_id' => $organization->id,
            'manager_id' => $director->id,
            'name' => 'Pédagogique',
            'slug' => 'pedagogique',
        ]);
        $firstProject = $this->createProject($director, $organization, $department, 'First Project');
        $secondProject = $this->createProject($director, $organization, $department, 'Second Project');
        $otherCategoryProject = $this->createProject($director, $organization, $department, 'Other Category Project');
        $firstProject->update(['project_category_id' => $category->id, 'position' => 1]);
        $secondProject->update(['project_category_id' => $category->id, 'position' => 2]);
        $otherCategoryProject->update(['project_category_id' => $otherCategory->id, 'position' => 1]);

        $response = $this
            ->actingAs($member)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('projects.reorder'), [
                'project_category_id' => $category->id,
                'positions' => [
                    ['id' => $secondProject->id, 'position' => 1],
                    ['id' => $firstProject->id, 'position' => 2],
                ],
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('projects', ['id' => $secondProject->id, 'position' => 1]);
        $this->assertDatabaseHas('projects', ['id' => $firstProject->id, 'position' => 2]);
        $this->assertDatabaseHas('projects', ['id' => $otherCategoryProject->id, 'position' => 1]);
    }

    public function test_project_priority_order_rejects_projects_from_another_organization(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'priority-current');
        [$otherOrganization, $otherDepartment] = $this->createContext($user, 'priority-other');
        $category = ProjectCategory::query()->create([
            'organization_id' => $organization->id,
            'manager_id' => $user->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);
        $outsideCategory = ProjectCategory::query()->create([
            'organization_id' => $otherOrganization->id,
            'manager_id' => $user->id,
            'name' => 'Other Support',
            'slug' => 'other-support',
        ]);
        $project = $this->createProject($user, $organization, $department, 'Current Project');
        $outsideProject = $this->createProject($user, $otherOrganization, $otherDepartment, 'Outside Project');
        $project->update(['project_category_id' => $category->id, 'position' => 1]);
        $outsideProject->update(['project_category_id' => $outsideCategory->id, 'position' => 2]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('projects.reorder'), [
                'project_category_id' => $category->id,
                'positions' => [
                    ['id' => $outsideProject->id, 'position' => 1],
                    ['id' => $project->id, 'position' => 2],
                ],
            ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'position' => 1]);
        $this->assertDatabaseHas('projects', ['id' => $outsideProject->id, 'position' => 2]);
    }

    public function test_project_priority_order_cannot_mix_project_categories(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'priority-category-scope');
        $support = ProjectCategory::query()->create([
            'organization_id' => $organization->id,
            'manager_id' => $user->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);
        $pedagogical = ProjectCategory::query()->create([
            'organization_id' => $organization->id,
            'manager_id' => $user->id,
            'name' => 'Pédagogique',
            'slug' => 'pedagogique',
        ]);
        $supportProject = $this->createProject($user, $organization, $department, 'Support Project');
        $pedagogicalProject = $this->createProject($user, $organization, $department, 'Pedagogical Project');
        $supportProject->update(['project_category_id' => $support->id, 'position' => 1]);
        $pedagogicalProject->update(['project_category_id' => $pedagogical->id, 'position' => 1]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('projects.reorder'), [
                'project_category_id' => $support->id,
                'positions' => [
                    ['id' => $pedagogicalProject->id, 'position' => 1],
                    ['id' => $supportProject->id, 'position' => 2],
                ],
            ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('projects', ['id' => $supportProject->id, 'position' => 1]);
        $this->assertDatabaseHas('projects', ['id' => $pedagogicalProject->id, 'position' => 1]);
    }

    public function test_project_priority_order_requires_a_category(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'priority-category-required');
        $project = $this->createProject($user, $organization, $department, 'Unscoped Priority Project');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('projects.reorder'), [
                'positions' => [
                    ['id' => $project->id, 'position' => 1],
                ],
            ]);

        $response->assertInvalid(['project_category_id']);
    }

    public function test_every_project_table_column_supports_backend_sorting(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'all-column-sorts');
        $project = $this->createProject($user, $organization, $department, 'Sortable Project');
        $category = ProjectCategory::query()->create([
            'organization_id' => $organization->id,
            'manager_id' => $user->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);
        $project->update([
            'project_category_id' => $category->id,
            'manager_id' => $user->id,
            'position' => 1,
        ]);
        $project->departments()->sync([$department->id]);
        $session = [
            'current_organization_id' => $organization->id,
            'current_department_id' => $department->id,
        ];

        foreach ([
            'name',
            'position',
            'action_plan',
            'axis',
            'departments',
            'category',
            'manager',
            'status',
            'priority',
            'progress',
            'due_date',
            'actions_count',
        ] as $sort) {
            $this->actingAs($user)
                ->withSession($session)
                ->get(route('projects.index', ['sort' => $sort, 'direction' => 'desc']))
                ->assertOk();
        }
    }

    private function createContext(User $user, string $key): array
    {
        $organization = Organization::query()->create([
            'name' => ucfirst($key).' Company',
            'slug' => $key.'-company',
        ]);
        $department = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => ucfirst($key).' Department',
        ]);
        $user->organizations()->attach($organization, [
            'status' => 'active',
            'is_active' => true,
        ]);
        $user->departments()->attach($department, [
            'is_manager' => true,
            'is_active' => true,
        ]);
        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Directeur',
            'slug' => 'director',
            'is_active' => true,
        ]);
        $user->roles()->attach($role, ['organization_id' => $organization->id]);

        return [$organization, $department];
    }

    private function createProject(
        User $user,
        Organization $organization,
        Department $department,
        string $name,
        string $portfolio = 'solutions',
    ): Project {
        $plan = ActionPlan::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
            'name' => $name.' Plan',
            'code' => 'PAC-'.strtoupper($portfolio).'-'.$organization->id.'-'.$department->id.'-'.str()->slug($name),
            'status' => 'active',
            'progress' => 0,
            'is_active' => true,
        ]);

        return Project::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'action_plan_id' => $plan->id,
            'created_by' => $user->id,
            'name' => $name,
            'status' => 'planned',
            'priority' => 'medium',
        ]);
    }
}
