<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkflowAIGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_generate_a_valid_workflow_without_saving_it(): void
    {
        [$user, $project, $workflow, $session] = $this->context();
        config(['services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAIResponse($this->validGraph()))]);

        $response = $this->actingAs($user)->withSession($session)->postJson(
            route('workflows.ai.generate', [$project, $workflow]),
            ['mode' => 'new', 'description' => 'Créer un processus de validation des demandes d’achat avec contrôle du budget.'],
        );

        $response->assertOk()
            ->assertJsonPath('graph.nodes.0.type', 'start')
            ->assertJsonPath('graph.nodes.1.position.x', 160)
            ->assertJsonPath('graph.nodes.1.position.y', 350)
            ->assertJsonCount(4, 'graph.edges');
        $this->assertDatabaseCount('workflow_nodes', 0);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['store'] === false
            && $request['text']['format']['strict'] === true);
    }

    public function test_description_is_required_and_limited(): void
    {
        [$user, $project, $workflow, $session] = $this->context();
        $this->actingAs($user)->withSession($session)
            ->postJson(route('workflows.ai.generate', [$project, $workflow]), ['mode' => 'new', 'description' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->actingAs($user)->withSession($session)
            ->postJson(route('workflows.ai.generate', [$project, $workflow]), ['mode' => 'new', 'description' => str_repeat('a', 5001)])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
    }

    public function test_missing_api_key_returns_an_explicit_error(): void
    {
        [$user, $project, $workflow, $session] = $this->context();
        config(['services.openai.api_key' => null]);

        $this->actingAs($user)->withSession($session)
            ->postJson(route('workflows.ai.generate', [$project, $workflow]), ['mode' => 'new', 'description' => 'Créer un processus simple de validation avec une notification finale.'])
            ->assertStatus(503)->assertJsonPath('message', 'L’assistant IA n’est pas configuré. Ajoutez OPENAI_API_KEY côté serveur.');
    }

    public function test_quota_error_is_reported_without_exposing_provider_details(): void
    {
        [$user, $project, $workflow, $session] = $this->context();
        config(['services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'sensitive provider detail']], 429)]);

        $this->actingAs($user)->withSession($session)
            ->postJson(route('workflows.ai.generate', [$project, $workflow]), ['mode' => 'new', 'description' => 'Créer un processus simple de validation avec une notification finale.'])
            ->assertTooManyRequests()->assertJsonPath('message', 'Le quota IA est temporairement atteint. Réessayez plus tard.');
    }

    public function test_invalid_provider_graph_is_rejected(): void
    {
        [$user, $project, $workflow, $session] = $this->context();
        config(['services.openai.api_key' => 'test-key']);
        $invalid = $this->validGraph();
        $invalid['edges'][0]['target'] = 'missing-node';
        Http::fake(['api.openai.com/*' => Http::response($this->openAIResponse($invalid))]);

        $this->actingAs($user)->withSession($session)
            ->postJson(route('workflows.ai.generate', [$project, $workflow]), ['mode' => 'new', 'description' => 'Créer un processus simple de validation avec une notification finale.'])
            ->assertUnprocessable()->assertJsonValidationErrors('generation');
    }

    public function test_invalid_graph_is_automatically_repaired_once(): void
    {
        [$user, $project, $workflow, $session] = $this->context();
        config(['services.openai.api_key' => 'test-key']);
        $cyclicGraph = $this->validGraph();
        $cyclicGraph['edges'][] = ['id' => 'e5', 'source' => 'approve', 'target' => 'check', 'label' => 'À corriger'];
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push($this->openAIResponse($cyclicGraph))
                ->push($this->openAIResponse($this->validGraph())),
        ]);

        $response = $this->actingAs($user)->withSession($session)->postJson(
            route('workflows.ai.generate', [$project, $workflow]),
            ['mode' => 'new', 'description' => 'Créer un processus sans boucle avec une validation et une notification finale.'],
        );

        $response->assertOk()
            ->assertJsonCount(4, 'graph.edges');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains((string) $request['input'], 'CORRECTION OBLIGATOIRE DU GRAPHE'));
    }

    public function test_invalid_provider_json_is_reported_cleanly(): void
    {
        [$user, $project, $workflow, $session] = $this->context();
        config(['services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{invalid']]]]])]);

        $this->actingAs($user)->withSession($session)
            ->postJson(route('workflows.ai.generate', [$project, $workflow]), ['mode' => 'new', 'description' => 'Créer un processus simple de validation avec une notification finale.'])
            ->assertStatus(503)->assertJsonPath('message', 'La réponse IA est invalide. Régénérez le workflow.');
    }

    public function test_non_manager_and_cross_organization_workflow_are_rejected(): void
    {
        [$manager, $project, $workflow, $session] = $this->context();
        $member = User::factory()->create();
        $member->organizations()->attach($project->organization_id, ['status' => 'active', 'is_active' => true]);
        $member->departments()->attach($project->department_id, ['is_primary' => true, 'is_active' => true]);

        $this->actingAs($member)->withSession($session)
            ->postJson(route('workflows.ai.generate', [$project, $workflow]), ['mode' => 'new', 'description' => 'Créer un processus simple de validation avec une notification finale.'])
            ->assertForbidden();

        $otherOrganization = Organization::query()->create(['name' => 'Other', 'slug' => 'other']);
        $otherDepartment = Department::query()->create(['organization_id' => $otherOrganization->id, 'name' => 'Other IT']);
        $otherProject = Project::query()->create(['organization_id' => $otherOrganization->id, 'department_id' => $otherDepartment->id, 'manager_id' => $manager->id, 'created_by' => $manager->id, 'name' => 'Other project']);
        $otherWorkflow = WorkflowDefinition::query()->create(['organization_id' => $otherOrganization->id, 'project_id' => $otherProject->id, 'created_by' => $manager->id, 'name' => 'Other workflow']);

        $this->actingAs($manager)->withSession($session)
            ->postJson(route('workflows.ai.generate', [$project, $otherWorkflow]), ['mode' => 'new', 'description' => 'Créer un processus simple de validation avec une notification finale.'])
            ->assertNotFound();
    }

    /** @return array{User, Project, WorkflowDefinition, array<string, int>} */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'WorkPilot', 'slug' => 'workpilot-ai']);
        $department = Department::query()->create(['organization_id' => $organization->id, 'name' => 'IT']);
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_primary' => true, 'is_active' => true]);
        $project = Project::query()->create(['organization_id' => $organization->id, 'department_id' => $department->id, 'manager_id' => $user->id, 'created_by' => $user->id, 'name' => 'Projet IA']);
        $workflow = WorkflowDefinition::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'created_by' => $user->id, 'name' => 'Workflow IA']);

        return [$user, $project, $workflow, ['current_organization_id' => $organization->id, 'current_department_id' => $department->id]];
    }

    /** @return array<string, mixed> */
    private function validGraph(): array
    {
        return [
            'workflow_name' => 'Validation achat', 'summary' => 'Validation contrôlée', 'assumptions' => ['Le responsable contrôle le budget.'],
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'label' => 'Début', 'description' => '', 'owner' => '', 'duration' => null, 'color' => 'mint'],
                ['id' => 'check', 'type' => 'condition', 'label' => 'Budget suffisant ?', 'description' => '', 'owner' => 'Responsable', 'duration' => 1, 'color' => 'lavender'],
                ['id' => 'approve', 'type' => 'approval', 'label' => 'Valider', 'description' => '', 'owner' => 'Achats', 'duration' => 1, 'color' => 'sand'],
                ['id' => 'accepted', 'type' => 'end', 'label' => 'Acceptée', 'description' => '', 'owner' => '', 'duration' => null, 'color' => 'mint'],
                ['id' => 'rejected', 'type' => 'end', 'label' => 'Rejetée', 'description' => '', 'owner' => '', 'duration' => null, 'color' => 'rose'],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'check', 'label' => ''],
                ['id' => 'e2', 'source' => 'check', 'target' => 'approve', 'label' => 'Oui'],
                ['id' => 'e3', 'source' => 'check', 'target' => 'rejected', 'label' => 'Non'],
                ['id' => 'e4', 'source' => 'approve', 'target' => 'accepted', 'label' => 'Accepté'],
            ],
        ];
    }

    /** @param array<string, mixed> $graph
     *  @return array<string, mixed> */
    private function openAIResponse(array $graph): array
    {
        return ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($graph)]]]]];
    }
}
