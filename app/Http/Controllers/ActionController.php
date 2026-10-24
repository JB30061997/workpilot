<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\ActionPlan;
use App\Models\Axis;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectWorkflowService;
use App\Support\ProjectAccessAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ActionController extends Controller
{
    public function __construct(
        private readonly ProjectAccessAuthorizer $projectAuthorizer,
        private readonly ProjectWorkflowService $workflowService,
    ) {}

    /**
     * Get the current organization for the authenticated user.
     */
    private function getCurrentOrganization(Request $request): Organization
    {
        $organization = $request->user()
            ->organizations()
            ->wherePivot('is_active', true)
            ->when(
                session('current_organization_id'),
                fn ($query, $organizationId) => $query->whereKey($organizationId)
            )
            ->first();

        if (! $organization && session()->has('current_organization_id')) {
            session()->forget([
                'current_organization_id',
                'current_department_id',
            ]);

            return $this->getCurrentOrganization($request);
        }

        abort_if(
            ! $organization,
            403,
            'Aucune société active n’est associée à votre compte.'
        );

        return $organization;
    }

    /**
     * Get the current department/service for the authenticated user.
     */
    private function getCurrentDepartment(
        Request $request,
        Organization $organization
    ): Department {
        $department = $request->user()
            ->departments()
            ->where('departments.organization_id', $organization->id)
            ->wherePivot('is_active', true)
            ->when(
                session('current_department_id'),
                fn ($query, $departmentId) => $query->whereKey($departmentId)
            )
            ->orderByDesc('department_members.is_primary')
            ->first();

        if (! $department && session()->has('current_department_id')) {
            session()->forget('current_department_id');

            return $this->getCurrentDepartment($request, $organization);
        }

        abort_if(
            ! $department,
            403,
            'Aucun service actif n’est associé à votre compte dans cette société.'
        );

        return $department;
    }

    /**
     * Return the current WorkPilot context.
     *
     * @return array{
     *     organization: Organization,
     *     department: Department
     * }
     */
    private function getContext(Request $request): array
    {
        $organization = $this->getCurrentOrganization($request);

        $department = $this->getCurrentDepartment(
            $request,
            $organization
        );

        return [
            'organization' => $organization,
            'department' => $department,
        ];
    }

    /**
     * Project actions are managed by the department director or project manager.
     * Standalone plan actions remain restricted to the department director.
     */
    private function authorizeActionManagement(
        Request $request,
        Organization $organization,
        Department $department,
        ?int $projectId
    ): void {
        if ($projectId === null) {
            $this->projectAuthorizer->authorizeProjectCreation(
                $request->user(),
                $organization->id,
                $department->id,
            );

            return;
        }

        $project = Project::query()
            ->whereKey($projectId)
            ->where('organization_id', $organization->id)
            ->where(fn ($query) => $query
                ->where('department_id', $department->id)
                ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery
                    ->whereKey($department->id)))
            ->firstOrFail();

        $this->projectAuthorizer->authorizeProjectActionManagement(
            $request->user(),
            $project,
            $organization->id,
            $department->id,
        );
    }

    /**
     * Ensure an action belongs to the current organization/service.
     */
    private function findScopedAction(
        Request $request,
        int $actionId
    ): Action {
        $context = $this->getContext($request);

        $organization = $context['organization'];
        $department = $context['department'];

        return Action::query()
            ->whereKey($actionId)
            ->where('organization_id', $organization->id)
            ->where(function ($query) use ($department) {
                $query
                    ->whereHas('project', function ($projectQuery) use ($department) {
                        $projectQuery->where(fn ($query) => $query
                            ->where('department_id', $department->id)
                            ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery
                                ->whereKey($department->id)));
                    })
                    ->orWhereHas(
                        'actionPlan',
                        function ($planQuery) use ($department) {
                            $planQuery->where(
                                'department_id',
                                $department->id
                            );
                        }
                    );
            })
            ->firstOrFail();
    }

    /**
     * Validate that all related resources belong
     * to the current organization and department.
     *
     * @throws ValidationException
     */
    private function validateRelations(
        array $data,
        Organization $organization,
        Department $department
    ): void {
        if (! empty($data['project_id'])) {
            $projectExists = Project::query()
                ->whereKey($data['project_id'])
                ->where('organization_id', $organization->id)
                ->where(fn ($query) => $query
                    ->where('department_id', $department->id)
                    ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery
                        ->whereKey($department->id)))
                ->exists();

            if (! $projectExists) {
                throw ValidationException::withMessages([
                    'project_id' => 'Le projet sélectionné n’appartient pas au service actuel.',
                ]);
            }
        }

        if (empty($data['project_id']) && ! empty($data['action_plan_id'])) {
            $planExists = ActionPlan::query()
                ->whereKey($data['action_plan_id'])
                ->where('organization_id', $organization->id)
                ->where('department_id', $department->id)
                ->exists();

            if (! $planExists) {
                throw ValidationException::withMessages([
                    'action_plan_id' => 'Le plan d’action sélectionné n’appartient pas au service actuel.',
                ]);
            }
        }

        if (empty($data['project_id']) && ! empty($data['axis_id'])) {
            $axisExists = Axis::query()
                ->whereKey($data['axis_id'])
                ->whereHas(
                    'actionPlan',
                    function ($query) use ($organization, $department) {
                        $query
                            ->where(
                                'organization_id',
                                $organization->id
                            )
                            ->where(
                                'department_id',
                                $department->id
                            );
                    }
                )
                ->exists();

            if (! $axisExists) {
                throw ValidationException::withMessages([
                    'axis_id' => 'L’axe sélectionné n’appartient pas au service actuel.',
                ]);
            }
        }

        if (! empty($data['owner_id'])) {
            $ownerExists = $department
                ->users()
                ->whereKey($data['owner_id'])
                ->wherePivot('is_active', true)
                ->exists();

            if (! $ownerExists) {
                throw ValidationException::withMessages([
                    'owner_id' => 'Le responsable sélectionné n’est pas membre actif du service actuel.',
                ]);
            }
        }

        /*
         * If an axis is selected together with an action plan,
         * ensure that the axis belongs to that exact plan.
         */
        if (
            empty($data['project_id'])
            && ! empty($data['axis_id'])
            && ! empty($data['action_plan_id'])
        ) {
            $axisBelongsToPlan = Axis::query()
                ->whereKey($data['axis_id'])
                ->where(
                    'action_plan_id',
                    $data['action_plan_id']
                )
                ->exists();

            if (! $axisBelongsToPlan) {
                throw ValidationException::withMessages([
                    'axis_id' => 'L’axe sélectionné ne correspond pas au plan d’action choisi.',
                ]);
            }
        }

        /*
         * If a project and an action plan are both selected,
         * ensure that the project belongs to the selected plan.
         */
        if (
            ! empty($data['project_id'])
            && ! empty($data['action_plan_id'])
        ) {
            $projectBelongsToPlan = Project::query()
                ->whereKey($data['project_id'])
                ->where(
                    'action_plan_id',
                    $data['action_plan_id']
                )
                ->exists();

            if (! $projectBelongsToPlan) {
                throw ValidationException::withMessages([
                    'project_id' => 'Le projet sélectionné ne correspond pas au plan d’action choisi.',
                ]);
            }
        }
    }

    /**
     * Validation rules shared by store and update.
     */
    private function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'project_id' => [
                'nullable',
                'integer',
                'exists:projects,id',
            ],

            'action_plan_id' => [
                'nullable',
                'integer',
                'exists:action_plans,id',
            ],

            'axis_id' => [
                'nullable',
                'integer',
                'exists:axes,id',
            ],

            'owner_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'title' => [
                $required,
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
                'max:10000',
            ],

            'start_date' => [
                'nullable',
                'date',
            ],

            'due_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],

            'actual_start_date' => [
                'nullable',
                'date',
            ],

            'actual_end_date' => [
                'nullable',
                'date',
                'after_or_equal:actual_start_date',
            ],

            'status' => [
                $required,
                Rule::in([
                    'not_started',
                    'in_progress',
                    'on_hold',
                    'completed',
                    'cancelled',
                ]),
            ],

            'priority' => [
                $required,
                Rule::in([
                    'low',
                    'medium',
                    'high',
                    'critical',
                ]),
            ],

            'progress' => [
                $required,
                'integer',
                'between:0,100',
            ],

            'position' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * Display actions of the current service.
     */
    public function index(Request $request)
    {
        $context = $this->getContext($request);

        $organization = $context['organization'];
        $department = $context['department'];

        $actions = Action::query()
            ->where(
                'organization_id',
                $organization->id
            )
            ->where(function ($query) use ($department) {
                $query
                    ->whereHas(
                        'project',
                        fn ($projectQuery) => $projectQuery
                            ->where(fn ($query) => $query
                                ->where('department_id', $department->id)
                                ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery
                                    ->whereKey($department->id)))
                    )
                    ->orWhereHas(
                        'actionPlan',
                        fn ($planQuery) => $planQuery
                            ->where(
                                'department_id',
                                $department->id
                            )
                    );
            })
            ->with([
                'project:id,name,department_id',
                'actionPlan:id,name,department_id',
                'axis:id,name,action_plan_id',
                'owner:id,name,email',
                'creator:id,name,email',
            ])
            ->withCount('tasks')
            ->orderBy('position')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],

            'department' => [
                'id' => $department->id,
                'name' => $department->name,
            ],

            'actions' => $actions,
        ]);
    }

    /**
     * Store a newly created action.
     */
    public function store(Request $request): RedirectResponse
    {
        $context = $this->getContext($request);

        $organization = $context['organization'];
        $department = $context['department'];

        $data = $request->validate(
            $this->rules(),
            [
                'title.required' => 'Le titre de l’action est obligatoire.',
                'due_date.after_or_equal' => 'La date d’échéance doit être postérieure ou égale à la date de début.',
                'actual_end_date.after_or_equal' => 'La date de fin réelle doit être postérieure ou égale à la date de début réelle.',
                'progress.between' => 'La progression doit être comprise entre 0 et 100.',
            ]
        );

        $this->validateRelations(
            $data,
            $organization,
            $department
        );
        $this->authorizeActionManagement(
            $request,
            $organization,
            $department,
            isset($data['project_id']) ? (int) $data['project_id'] : null,
        );

        if (! empty($data['project_id'])) {
            $project = Project::query()->findOrFail($data['project_id']);
            $data['action_plan_id'] = $project->action_plan_id;
            $data['axis_id'] = $project->axis_id;
        }

        DB::transaction(function () use (
            $data,
            $organization,
            $request
        ) {
            Action::create([
                'organization_id' => $organization->id,

                'project_id' => $data['project_id'] ?? null,
                'action_plan_id' => $data['action_plan_id'] ?? null,
                'axis_id' => $data['axis_id'] ?? null,

                'owner_id' => $data['owner_id'] ?? null,
                'created_by' => $request->user()->id,

                'title' => $data['title'],
                'description' => $data['description'] ?? null,

                'start_date' => $data['start_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'actual_start_date' => $data['actual_start_date'] ?? null,
                'actual_end_date' => $data['actual_end_date'] ?? null,

                'status' => $data['status'],
                'priority' => $data['priority'],

                'progress' => $data['progress'],
                'position' => $data['position'] ?? 0,
            ]);
        });

        return back()->with(
            'success',
            'Action créée avec succès.'
        );
    }

    /**
     * Display the specified action.
     */
    public function show(
        Request $request,
        string $id
    ) {
        $action = $this->findScopedAction(
            $request,
            (int) $id
        );

        $action->load([
            'organization:id,name',
            'project:id,name,department_id',
            'actionPlan:id,name,department_id',
            'axis:id,name,action_plan_id',
            'owner:id,name,email',
            'creator:id,name,email',

            'tasks' => function ($query) {
                $query
                    ->with([
                        'status',
                        'assignees:id,name,email',
                    ])
                    ->orderBy('position');
            },
        ]);

        return response()->json([
            'action' => $action,
        ]);
    }

    public function duplicate(Request $request, string $id): RedirectResponse
    {
        $action = $this->findScopedAction($request, (int) $id);
        $context = $this->getContext($request);
        $this->authorizeActionManagement(
            $request,
            $context['organization'],
            $context['department'],
            $action->project_id !== null ? (int) $action->project_id : null,
        );

        DB::transaction(fn (): Action => $this->workflowService->duplicateAction(
            $action,
            $request->user(),
        ));

        return back()->with('success', 'Action dupliquée avec succès.');
    }

    /**
     * Update the specified action.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $action = $this->findScopedAction(
            $request,
            (int) $id
        );

        $context = $this->getContext($request);

        $organization = $context['organization'];
        $department = $context['department'];

        $this->authorizeActionManagement(
            $request,
            $organization,
            $department,
            $action->project_id !== null ? (int) $action->project_id : null,
        );

        $data = $request->validate(
            $this->rules(true),
            [
                'due_date.after_or_equal' => 'La date d’échéance doit être postérieure ou égale à la date de début.',
                'actual_end_date.after_or_equal' => 'La date de fin réelle doit être postérieure ou égale à la date de début réelle.',
                'progress.between' => 'La progression doit être comprise entre 0 et 100.',
            ]
        );

        /*
         * Validate relations using the final state of the action,
         * not only the fields sent by the frontend.
         */
        $relationData = [
            'project_id' => array_key_exists('project_id', $data)
                ? $data['project_id']
                : $action->project_id,

            'action_plan_id' => array_key_exists('action_plan_id', $data)
                ? $data['action_plan_id']
                : $action->action_plan_id,

            'axis_id' => array_key_exists('axis_id', $data)
                ? $data['axis_id']
                : $action->axis_id,

            'owner_id' => array_key_exists('owner_id', $data)
                ? $data['owner_id']
                : $action->owner_id,
        ];

        $this->validateRelations(
            $relationData,
            $organization,
            $department
        );

        if (! empty($relationData['project_id'])) {
            $project = Project::query()->findOrFail($relationData['project_id']);
            $data['action_plan_id'] = $project->action_plan_id;
            $data['axis_id'] = $project->axis_id;
        }

        if ((int) ($relationData['project_id'] ?? 0) !== (int) ($action->project_id ?? 0)) {
            $this->authorizeActionManagement(
                $request,
                $organization,
                $department,
                $relationData['project_id'] !== null ? (int) $relationData['project_id'] : null,
            );
        }

        DB::transaction(function () use (
            $action,
            $data
        ) {
            $action->update($data);
        });

        return back()->with(
            'success',
            'Action mise à jour avec succès.'
        );
    }

    /**
     * Remove the specified action.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $action = $this->findScopedAction(
            $request,
            (int) $id
        );
        $context = $this->getContext($request);
        $this->authorizeActionManagement(
            $request,
            $context['organization'],
            $context['department'],
            $action->project_id !== null ? (int) $action->project_id : null,
        );

        /*
         * An action containing tasks should not be deleted silently.
         * This prevents accidental loss of operational work.
         */
        if ($action->tasks()->exists()) {
            throw ValidationException::withMessages([
                'action' => 'Cette action contient encore des tâches. Supprimez ou réaffectez les tâches avant de supprimer l’action.',
            ]);
        }

        DB::transaction(function () use ($action) {
            $action->delete();
        });

        return back()->with(
            'success',
            'Action supprimée avec succès.'
        );
    }
}
