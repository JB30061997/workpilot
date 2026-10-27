<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkflowRequest;
use App\Http\Requests\UpdateWorkflowRequest;
use App\Models\Project;
use App\Models\WorkflowDefinition;
use App\Services\WorkContextDepartmentService;
use App\Support\ProjectAccessAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly ProjectAccessAuthorizer $authorizer,
        private readonly WorkContextDepartmentService $departmentAccess,
    ) {}

    public function library(Request $request): Response
    {
        $organizationId = (int) session('current_organization_id');
        $departmentId = (int) session('current_department_id');
        $user = $request->user();
        abort_unless($organizationId && $departmentId, 403);
        abort_unless($user->organizations()->whereKey($organizationId)->wherePivot('is_active', true)->exists(), 403);
        abort_unless($this->departmentAccess->canAccessDepartment($user, $organizationId, $departmentId), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
            'project_id' => ['nullable', 'integer'],
            'creator_id' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(['updated_at', 'name', 'project', 'status', 'nodes_count'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:10,50'],
        ]);

        $scope = WorkflowDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereHas('project', fn ($query) => $query->where(function ($projectQuery) use ($departmentId): void {
                $projectQuery->where('department_id', $departmentId)
                    ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery->whereKey($departmentId));
            }));

        $summary = [
            'total' => (clone $scope)->count(),
            'draft' => (clone $scope)->where('status', 'draft')->count(),
            'active' => (clone $scope)->where('status', 'active')->count(),
            'nodes' => (clone $scope)->withCount('nodes')->get()->sum('nodes_count'),
        ];

        $sort = $filters['sort'] ?? 'updated_at';
        $direction = $filters['direction'] ?? 'desc';
        $workflows = (clone $scope)
            ->with(['project:id,name,code,manager_id', 'creator:id,name'])
            ->withCount(['nodes', 'edges'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('project', fn ($projectQuery) => $projectQuery->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['project_id'] ?? null, fn ($query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['creator_id'] ?? null, fn ($query, int $creatorId) => $query->where('created_by', $creatorId))
            ->when($sort === 'project', fn ($query) => $query->orderBy(Project::select('name')->whereColumn('projects.id', 'workflow_definitions.project_id'), $direction))
            ->when($sort !== 'project', fn ($query) => $query->orderBy($sort, $direction))
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        $projects = Project::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($departmentId): void {
                $query->where('department_id', $departmentId)
                    ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery->whereKey($departmentId));
            })
            ->whereHas('workflows')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $creators = $user->newQuery()
            ->whereHas('createdWorkflows', fn ($query) => $query->whereIn('id', (clone $scope)->select('workflow_definitions.id')))
            ->orderBy('name')
            ->get(['users.id', 'users.name']);

        return Inertia::render('Workflows/Library', [
            'workflows' => $workflows,
            'filters' => $filters,
            'projects' => $projects,
            'creators' => $creators,
            'summary' => $summary,
        ]);
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorizeProject($request, $project);

        return Inertia::render('Workflows/Index', [
            'project' => $project->only('id', 'name', 'code'),
            'workflows' => $project->workflows()->with('creator:id,name')->withCount(['nodes', 'edges'])->latest('updated_at')->get(),
            'canManage' => $this->canManage($request, $project),
        ]);
    }

    public function store(StoreWorkflowRequest $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($request, $project, true);
        $workflow = DB::transaction(function () use ($request, $project): WorkflowDefinition {
            $workflow = $project->workflows()->create([...$request->validated(), 'organization_id' => $project->organization_id, 'created_by' => $request->user()->id]);
            $workflow->nodes()->createMany([
                ['node_key' => 'start', 'type' => 'start', 'label' => 'Début', 'configuration' => [], 'position_x' => 140, 'position_y' => 220],
                ['node_key' => 'end', 'type' => 'end', 'label' => 'Fin', 'configuration' => [], 'position_x' => 680, 'position_y' => 220],
            ]);

            return $workflow;
        });

        return redirect()->route('workflows.edit', [$project, $workflow]);
    }

    public function edit(Request $request, Project $project, WorkflowDefinition $workflow): Response
    {
        $this->authorizeWorkflow($request, $project, $workflow);

        return Inertia::render('Workflows/Builder', [
            'project' => $project->only('id', 'name', 'code'),
            'workflow' => $workflow->load(['nodes', 'edges']),
            'canManage' => $this->canManage($request, $project),
            'aiConfigured' => filled(config('services.openai.api_key')),
        ]);
    }

    public function update(UpdateWorkflowRequest $request, Project $project, WorkflowDefinition $workflow): RedirectResponse
    {
        $this->authorizeWorkflow($request, $project, $workflow, true);
        abort_unless($workflow->status === 'draft', 422, 'Seuls les brouillons sont modifiables.');
        $data = $request->validated();
        $this->validateGraph($data['nodes'], $data['edges']);

        DB::transaction(function () use ($workflow, $data): void {
            $workflow->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $workflow->edges()->delete();
            $workflow->nodes()->delete();
            foreach ($data['nodes'] as $node) {
                $workflow->nodes()->create(['node_key' => $node['id'], 'type' => $node['type'], 'label' => $node['label'], 'configuration' => $node['data'] ?? [], 'position_x' => $node['position']['x'], 'position_y' => $node['position']['y']]);
            }
            foreach ($data['edges'] as $edge) {
                $workflow->edges()->create(['edge_key' => $edge['id'], 'source_key' => $edge['source'], 'target_key' => $edge['target'], 'source_handle' => $edge['sourceHandle'] ?? null, 'label' => $edge['label'] ?? null]);
            }
        });

        return back()->with('success', 'Workflow enregistré avec succès.');
    }

    public function publish(Request $request, Project $project, WorkflowDefinition $workflow): RedirectResponse
    {
        $this->authorizeWorkflow($request, $project, $workflow, true);
        abort_unless($workflow->status === 'draft', 422, 'Ce workflow est déjà publié ou archivé.');
        $workflow->load(['nodes', 'edges']);
        $nodes = $workflow->nodes->map(fn ($node): array => ['id' => $node->node_key, 'type' => $node->type]);
        $edges = $workflow->edges->map(fn ($edge): array => ['source' => $edge->source_key, 'target' => $edge->target_key]);
        $this->validateGraph($nodes, $edges, true);
        $workflow->update(['status' => 'active']);

        return back()->with('success', 'Workflow publié. Il est maintenant en lecture seule.');
    }

    public function duplicate(Request $request, Project $project, WorkflowDefinition $workflow): RedirectResponse
    {
        $this->authorizeWorkflow($request, $project, $workflow, true);
        $workflow->load(['nodes', 'edges']);
        $copy = DB::transaction(function () use ($request, $project, $workflow): WorkflowDefinition {
            $copy = $project->workflows()->create(['organization_id' => $project->organization_id, 'created_by' => $request->user()->id, 'name' => $workflow->name.' (Copie)', 'description' => $workflow->description, 'status' => 'draft', 'version' => $workflow->version + 1]);
            $copy->nodes()->createMany($workflow->nodes->map(fn ($node): array => ['node_key' => $node->node_key, 'type' => $node->type, 'label' => $node->label, 'configuration' => $node->configuration, 'position_x' => $node->position_x, 'position_y' => $node->position_y])->all());
            $copy->edges()->createMany($workflow->edges->map(fn ($edge): array => ['edge_key' => $edge->edge_key, 'source_key' => $edge->source_key, 'target_key' => $edge->target_key, 'source_handle' => $edge->source_handle, 'label' => $edge->label])->all());

            return $copy;
        });

        return redirect()->route('workflows.edit', [$project, $copy])->with('success', 'Copie créée.');
    }

    public function destroy(Request $request, Project $project, WorkflowDefinition $workflow): RedirectResponse
    {
        $this->authorizeWorkflow($request, $project, $workflow, true);
        abort_unless($workflow->status === 'draft', 422, 'Seul un brouillon peut être supprimé.');
        $workflow->delete();

        return redirect()->route('workflows.index', $project)->with('success', 'Workflow supprimé.');
    }

    /** @param Collection<int, array<string, mixed>>|array<int, array<string, mixed>> $nodes
     *  @param Collection<int, array<string, mixed>>|array<int, array<string, mixed>> $edges */
    private function validateGraph(Collection|array $nodes, Collection|array $edges, bool $forPublication = false): void
    {
        $nodes = collect($nodes);
        $edges = collect($edges);
        $nodeIds = $nodes->pluck('id');
        if ($nodeIds->unique()->count() !== $nodeIds->count()) {
            throw ValidationException::withMessages(['nodes' => 'Chaque bloc doit avoir un identifiant unique.']);
        }
        $edgeIds = $edges->pluck('id')->filter();
        if ($edgeIds->unique()->count() !== $edgeIds->count()) {
            throw ValidationException::withMessages(['edges' => 'Chaque connexion doit avoir un identifiant unique.']);
        }
        foreach ($edges as $edge) {
            if (! $nodeIds->contains($edge['source']) || ! $nodeIds->contains($edge['target']) || $edge['source'] === $edge['target']) {
                throw ValidationException::withMessages(['edges' => 'Une connexion pointe vers un bloc inexistant ou vers elle-même.']);
            }
        }
        if (! $forPublication) {
            return;
        }
        $starts = $nodes->where('type', 'start');
        if ($starts->count() !== 1 || $nodes->where('type', 'end')->isEmpty()) {
            throw ValidationException::withMessages(['workflow' => 'La publication exige exactement un début et au moins une fin.']);
        }
        $reachable = collect([$starts->first()['id']]);
        do {
            $before = $reachable->count();
            $reachable = $reachable->merge($edges->whereIn('source', $reachable)->pluck('target'))->unique();
        } while ($reachable->count() > $before);
        if ($reachable->count() !== $nodes->count()) {
            throw ValidationException::withMessages(['workflow' => 'Tous les blocs doivent être reliés au bloc de début avant publication.']);
        }
    }

    private function authorizeWorkflow(Request $request, Project $project, WorkflowDefinition $workflow, bool $manage = false): void
    {
        $this->authorizeProject($request, $project, $manage);
        abort_unless((int) $workflow->project_id === (int) $project->id && (int) $workflow->organization_id === (int) $project->organization_id, 404);
    }

    private function authorizeProject(Request $request, Project $project, bool $manage = false): void
    {
        $organizationId = (int) session('current_organization_id');
        $departmentId = (int) session('current_department_id');
        $user = $request->user();
        abort_unless($organizationId && $departmentId && (int) $project->organization_id === $organizationId, 403);
        abort_unless($user->organizations()->whereKey($organizationId)->wherePivot('is_active', true)->exists(), 403);
        abort_unless($this->departmentAccess->canAccessDepartment($user, $organizationId, $departmentId), 403);
        abort_unless((int) $project->department_id === $departmentId || $project->departments()->whereKey($departmentId)->exists(), 403);
        if ($manage) {
            $this->authorizer->authorizeProjectManagement($user, $project, $organizationId, $departmentId);

            return;
        }
        abort_unless(
            $this->departmentAccess->isDirectorGeneral($user, $organizationId)
                || $this->canManage($request, $project)
                || $project->members()->whereKey($user->id)->exists()
                || (int) $project->created_by === (int) $user->id,
            403,
        );
    }

    private function canManage(Request $request, Project $project): bool
    {
        return $this->authorizer->canManageProject($request->user(), $project, (int) session('current_organization_id'), (int) session('current_department_id'));
    }
}
