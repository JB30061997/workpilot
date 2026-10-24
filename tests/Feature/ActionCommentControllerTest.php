<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionComment;
use App\Models\ActionPlan;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionCommentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_comment_on_an_action_in_the_selected_context(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'selected');
        $action = $this->createAction($user, $organization, $department, 'Commented action');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('action-comments.store'), [
                'action_id' => $action->id,
                'content' => 'Le démarrage réel est confirmé.',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('action_comments', [
            'action_id' => $action->id,
            'user_id' => $user->id,
            'content' => 'Le démarrage réel est confirmé.',
        ]);
    }

    public function test_user_cannot_comment_on_an_action_from_another_context(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'selected');
        [$otherOrganization, $otherDepartment] = $this->createContext($user, 'other');
        $action = $this->createAction($user, $otherOrganization, $otherDepartment, 'Restricted action');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('action-comments.store'), [
                'action_id' => $action->id,
                'content' => 'This must not be stored.',
            ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('action_comments', ['action_id' => $action->id]);
    }

    public function test_action_tracking_dates_can_be_updated(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'tracking');
        $action = $this->createAction($user, $organization, $department, 'Tracked action');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('actions.update', $action), [
                'progress' => 45,
                'start_date' => '2026-10-01',
                'due_date' => '2026-10-20',
                'actual_start_date' => '2026-10-03',
                'actual_end_date' => '2026-10-18',
            ]);

        $response->assertSessionHasNoErrors();
        $action->refresh();

        $this->assertSame(45, $action->progress);
        $this->assertSame('2026-10-01', $action->start_date?->toDateString());
        $this->assertSame('2026-10-20', $action->due_date?->toDateString());
        $this->assertSame('2026-10-03', $action->actual_start_date?->toDateString());
        $this->assertSame('2026-10-18', $action->actual_end_date?->toDateString());
    }

    public function test_author_can_update_their_action_comment(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'update-comment');
        $action = $this->createAction($user, $organization, $department, 'Updated comment action');
        $comment = $this->createComment($action, $user, 'Original comment');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('action-comments.update', $comment), [
                'content' => 'Updated comment',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('action_comments', [
            'id' => $comment->id,
            'content' => 'Updated comment',
        ]);
    }

    public function test_author_can_delete_their_action_comment(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'delete-comment');
        $action = $this->createAction($user, $organization, $department, 'Deleted comment action');
        $comment = $this->createComment($action, $user, 'Comment to delete');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->delete(route('action-comments.destroy', $comment));

        $response->assertSessionHasNoErrors();
        $this->assertModelMissing($comment);
    }

    public function test_user_cannot_update_another_users_action_comment(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        [$organization, $department] = $this->createContext($author, 'protected-update');
        $this->attachUserToContext($otherUser, $organization, $department);
        $action = $this->createAction($author, $organization, $department, 'Protected comment action');
        $comment = $this->createComment($action, $author, 'Protected comment');

        $response = $this
            ->actingAs($otherUser)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('action-comments.update', $comment), [
                'content' => 'Unauthorized update',
            ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('action_comments', [
            'id' => $comment->id,
            'content' => 'Protected comment',
        ]);
    }

    public function test_user_cannot_delete_another_users_action_comment(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        [$organization, $department] = $this->createContext($author, 'protected-delete');
        $this->attachUserToContext($otherUser, $organization, $department);
        $action = $this->createAction($author, $organization, $department, 'Protected deletion action');
        $comment = $this->createComment($action, $author, 'Comment must remain');

        $response = $this
            ->actingAs($otherUser)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->delete(route('action-comments.destroy', $comment));

        $response->assertForbidden();
        $this->assertModelExists($comment);
    }

    /**
     * @return array{Organization, Department}
     */
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
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_active' => true]);

        return [$organization, $department];
    }

    private function createAction(
        User $user,
        Organization $organization,
        Department $department,
        string $title,
    ): Action {
        $plan = ActionPlan::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
            'name' => $title.' plan',
            'status' => 'active',
            'progress' => 0,
            'is_active' => true,
        ]);
        $project = Project::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'action_plan_id' => $plan->id,
            'created_by' => $user->id,
            'manager_id' => $user->id,
            'name' => $title.' project',
            'status' => 'in_progress',
            'priority' => 'medium',
            'progress' => 0,
        ]);

        return Action::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'action_plan_id' => $plan->id,
            'created_by' => $user->id,
            'title' => $title,
            'status' => 'not_started',
            'priority' => 'medium',
            'progress' => 0,
        ]);
    }

    private function createComment(Action $action, User $user, string $content): ActionComment
    {
        return ActionComment::query()->create([
            'action_id' => $action->id,
            'user_id' => $user->id,
            'content' => $content,
        ]);
    }

    private function attachUserToContext(
        User $user,
        Organization $organization,
        Department $department,
    ): void {
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_active' => true]);
    }
}
