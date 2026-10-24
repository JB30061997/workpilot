<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_category_with_active_organization_member_as_manager(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $organization = $this->createOrganization($owner, 'category-create');
        $manager->organizations()->attach($organization, [
            'status' => 'active',
            'is_active' => true,
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('project-categories.store'), [
                'name' => 'Pédagogique',
                'manager_id' => $manager->id,
                'description' => 'Projets de formation',
                'is_active' => true,
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('project_categories', [
            'organization_id' => $organization->id,
            'manager_id' => $manager->id,
            'name' => 'Pédagogique',
            'slug' => 'pedagogique',
        ]);
    }

    public function test_category_rejects_manager_from_another_organization(): void
    {
        $owner = User::factory()->create();
        $outsideManager = User::factory()->create();
        $organization = $this->createOrganization($owner, 'category-scope');
        $this->createOrganization($outsideManager, 'outside-category-scope');

        $response = $this->actingAs($owner)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('project-categories.store'), [
                'name' => 'Support',
                'manager_id' => $outsideManager->id,
                'is_active' => true,
            ]);

        $response->assertInvalid(['manager_id']);
        $this->assertDatabaseMissing('project_categories', [
            'organization_id' => $organization->id,
            'name' => 'Support',
        ]);
    }

    public function test_category_with_projects_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner, 'category-delete');
        $category = ProjectCategory::query()->create([
            'organization_id' => $organization->id,
            'manager_id' => $owner->id,
            'name' => 'Support',
            'slug' => 'support',
            'is_active' => true,
        ]);
        $organization->projects()->create([
            'project_category_id' => $category->id,
            'created_by' => $owner->id,
            'name' => 'Support Project',
            'status' => 'planned',
            'priority' => 'medium',
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['current_organization_id' => $organization->id])
            ->delete(route('project-categories.destroy', $category));

        $response->assertInvalid(['category']);
        $this->assertModelExists($category);
    }

    private function createOrganization(User $owner, string $slug): Organization
    {
        $organization = Organization::query()->create([
            'name' => str($slug)->headline(),
            'slug' => $slug,
        ]);
        $owner->organizations()->attach($organization, [
            'status' => 'active',
            'is_owner' => true,
            'is_active' => true,
        ]);

        return $organization;
    }
}
