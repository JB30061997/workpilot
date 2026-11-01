<?php

namespace Tests\Feature;

use App\Models\ActionPlan;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Organization;
use App\Models\PlanningYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ActivityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_model_changes_are_recorded_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        [$organization, $department, $planningYear] = $this->createContext($user, 'audit-recording');
        $session = [
            'current_organization_id' => $organization->id,
            'current_department_id' => $department->id,
        ];

        $this->actingAs($user)
            ->withSession($session)
            ->post(route('action-plans.store'), [
                'name' => 'Plan audité',
                'planning_year_id' => $planningYear->id,
                'status' => 'active',
                'progress' => 10,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $plan = ActionPlan::query()->where('name', 'Plan audité')->firstOrFail();
        $createdActivity = Activity::query()
            ->where('user_id', $user->id)
            ->where('subject_type', ActionPlan::class)
            ->where('subject_id', $plan->id)
            ->where('event', 'created')
            ->firstOrFail();
        $this->assertSame('Plan d’action « Plan audité » créé.', $createdActivity->description);
        $this->assertSame('Plan audité', $createdActivity->new_values['name']);
        $this->assertSame('action-plans.store', $createdActivity->metadata['route']);

        $this->actingAs($user)
            ->withSession($session)
            ->patch(route('action-plans.update', $plan), ['progress' => 65])
            ->assertSessionHasNoErrors();

        $updatedActivity = Activity::query()
            ->where('user_id', $user->id)
            ->where('subject_type', ActionPlan::class)
            ->where('subject_id', $plan->id)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(10, $updatedActivity->old_values['progress']);
        $this->assertSame(65, $updatedActivity->new_values['progress']);
    }

    public function test_sensitive_attributes_are_never_stored_in_the_audit_values(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'audit-secrets');
        $this->actingAs($user)->withSession([
            'current_organization_id' => $organization->id,
            'current_department_id' => $department->id,
        ]);

        $user->update(['password' => Hash::make('new-secret-password')]);

        $activity = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->where('event', 'updated')
            ->firstOrFail();
        $this->assertArrayNotHasKey('password', $activity->old_values ?? []);
        $this->assertArrayNotHasKey('password', $activity->new_values ?? []);
    }

    public function test_history_page_only_displays_the_authenticated_users_activities(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'personal-history');
        $otherUser->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $otherUser->departments()->attach($department, ['is_active' => true]);
        $ownActivity = Activity::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'event' => 'updated',
            'description' => 'Mon événement visible',
        ]);
        $otherActivity = Activity::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $otherUser->id,
            'event' => 'deleted',
            'description' => 'Événement privé autre utilisateur',
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('activities.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Index')
            ->has('activities.data', 1)
            ->where('activities.data.0.id', $ownActivity->id)
            ->where('activities.data.0.description', 'Mon événement visible')
            ->where('summary.total', 1));

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('activities.show', $otherActivity))
            ->assertNotFound();
    }

    public function test_history_filters_are_applied_only_inside_the_personal_history(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'history-filters');
        $this->travelTo('2026-10-10 10:00:00');
        Activity::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'event' => 'created',
            'description' => 'Projet Alpha créé',
        ]);
        $this->travelTo('2026-10-20 10:00:00');
        Activity::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'event' => 'updated',
            'description' => 'Projet Beta modifié',
        ]);
        $this->travelBack();

        $response = $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('activities.index', [
                'event' => 'updated',
                'search' => 'Beta',
                'date_from' => '2026-10-15',
                'date_to' => '2026-10-25',
            ]));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.description', 'Projet Beta modifié')
            ->where('filters.event', 'updated')
            ->where('filters.search', 'Beta'));
    }

    /** @return array{Organization, Department, PlanningYear} */
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
        $planningYear = PlanningYear::query()->create([
            'organization_id' => $organization->id,
            'label' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
        ]);
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_active' => true]);

        return [$organization, $department, $planningYear];
    }
}
