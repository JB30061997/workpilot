<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItem;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChecklistItemController extends Controller
{
    /**
     * Resolve the active organization of the authenticated user.
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
     * Resolve the active department/service.
     */
    private function getCurrentDepartment(
        Request $request,
        Organization $organization
    ): Department {
        $department = $request->user()
            ->departments()
            ->where(
                'departments.organization_id',
                $organization->id
            )
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
     * Resolve current WorkPilot context.
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
     * Find a task strictly inside the current
     * organization and department.
     */
    private function findScopedTask(
        Request $request,
        int $taskId
    ): Task {
        $context = $this->getContext($request);

        return Task::query()
            ->whereKey($taskId)
            ->where(
                'organization_id',
                $context['organization']->id
            )
            ->where(
                'department_id',
                $context['department']->id
            )
            ->firstOrFail();
    }

    /**
     * Find a checklist item through a task belonging
     * to the current organization and department.
     */
    private function findScopedChecklistItem(
        Request $request,
        int $checklistItemId
    ): ChecklistItem {
        $context = $this->getContext($request);

        return ChecklistItem::query()
            ->whereKey($checklistItemId)
            ->whereHas(
                'task',
                function ($query) use ($context) {
                    $query
                        ->where(
                            'organization_id',
                            $context['organization']->id
                        )
                        ->where(
                            'department_id',
                            $context['department']->id
                        );
                }
            )
            ->firstOrFail();
    }

    /**
     * Display checklist items of the current service.
     */
    public function index(Request $request): JsonResponse
    {
        $context = $this->getContext($request);

        $validated = $request->validate([
            'task_id' => [
                'nullable',
                'integer',
                'exists:tasks,id',
            ],

            'is_completed' => [
                'nullable',
                'boolean',
            ],

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'between:1,100',
            ],
        ]);

        if (! empty($validated['task_id'])) {
            $this->findScopedTask(
                $request,
                (int) $validated['task_id']
            );
        }

        $items = ChecklistItem::query()
            ->whereHas(
                'task',
                function ($query) use ($context) {
                    $query
                        ->where(
                            'organization_id',
                            $context['organization']->id
                        )
                        ->where(
                            'department_id',
                            $context['department']->id
                        );
                }
            )

            ->when(
                ! empty($validated['task_id']),
                fn ($query) => $query->where(
                    'task_id',
                    $validated['task_id']
                )
            )

            ->when(
                array_key_exists('is_completed', $validated),
                fn ($query) => $query->where(
                    'is_completed',
                    $validated['is_completed']
                )
            )

            ->when(
                ! empty($validated['search']),
                fn ($query) => $query->where(
                    'title',
                    'like',
                    '%'.trim($validated['search']).'%'
                )
            )

            ->with([
                'completedBy:id,name,email',
                'task:id,title,organization_id,department_id',
            ])

            ->orderBy('task_id')
            ->orderBy('position')
            ->orderBy('id')

            ->paginate($validated['per_page'] ?? 50)
            ->withQueryString();

        return response()->json([
            'organization' => [
                'id' => $context['organization']->id,
                'name' => $context['organization']->name,
            ],

            'department' => [
                'id' => $context['department']->id,
                'name' => $context['department']->name,
            ],

            'checklist_items' => $items,
        ]);
    }

    /**
     * Store a new checklist item.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'task_id' => [
                'required',
                'integer',
                'exists:tasks,id',
            ],

            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'position' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ], [
            'task_id.required' => 'La tâche est obligatoire.',

            'task_id.exists' => 'La tâche sélectionnée est introuvable.',

            'title.required' => 'Le titre de l’élément est obligatoire.',

            'title.max' => 'Le titre ne peut pas dépasser 255 caractères.',

            'position.integer' => 'La position doit être un nombre entier.',

            'position.min' => 'La position ne peut pas être négative.',
        ]);

        $task = $this->findScopedTask(
            $request,
            (int) $data['task_id']
        );

        DB::transaction(function () use ($task, $data) {
            /*
             * If no explicit position is provided,
             * append the item to the end of the checklist.
             */
            $position = $data['position']
                ?? (
                    (int) $task
                        ->checklistItems()
                        ->max('position')
                    + 1
                );

            ChecklistItem::create([
                'task_id' => $task->id,
                'title' => $data['title'],

                'is_completed' => false,
                'completed_by' => null,
                'completed_at' => null,

                'position' => $position,
            ]);
        });

        return back()->with(
            'success',
            'Élément ajouté à la checklist.'
        );
    }

    /**
     * Display one checklist item.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $item = $this->findScopedChecklistItem(
            $request,
            (int) $id
        );

        $item->load([
            'task:id,title,organization_id,department_id',
            'completedBy:id,name,email',
        ]);

        return response()->json([
            'checklist_item' => $item,
        ]);
    }

    /**
     * Update a checklist item.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $item = $this->findScopedChecklistItem(
            $request,
            (int) $id
        );

        $data = $request->validate([
            'task_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:tasks,id',
            ],

            'title' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'position' => [
                'sometimes',
                'integer',
                'min:0',
            ],

            'is_completed' => [
                'sometimes',
                'boolean',
            ],
        ], [
            'task_id.required' => 'La tâche est obligatoire.',

            'task_id.exists' => 'La tâche sélectionnée est introuvable.',

            'title.required' => 'Le titre de l’élément est obligatoire.',

            'title.max' => 'Le titre ne peut pas dépasser 255 caractères.',

            'position.integer' => 'La position doit être un nombre entier.',

            'position.min' => 'La position ne peut pas être négative.',

            'is_completed.boolean' => 'L’état de l’élément est invalide.',
        ]);

        /*
         * If moving the checklist item to another task,
         * validate the destination against the current service.
         */
        if (array_key_exists('task_id', $data)) {
            $task = $this->findScopedTask(
                $request,
                (int) $data['task_id']
            );

            $data['task_id'] = $task->id;
        }

        DB::transaction(function () use (
            $item,
            $data,
            $request
        ) {
            /*
             * Completion metadata is controlled exclusively
             * by the backend.
             *
             * The frontend sends only is_completed.
             */
            if (array_key_exists('is_completed', $data)) {
                $newState = (bool) $data['is_completed'];

                if ($newState && ! $item->is_completed) {
                    $data['completed_by'] = $request->user()->id;
                    $data['completed_at'] = now();
                }

                if (! $newState && $item->is_completed) {
                    $data['completed_by'] = null;
                    $data['completed_at'] = null;
                }
            }

            $item->update($data);
        });

        return back()->with(
            'success',
            'Checklist mise à jour avec succès.'
        );
    }

    /**
     * Mark a checklist item as completed.
     */
    public function complete(
        Request $request,
        string $id
    ): RedirectResponse {
        $item = $this->findScopedChecklistItem(
            $request,
            (int) $id
        );

        if ($item->is_completed) {
            return back()->with(
                'success',
                'Cet élément est déjà terminé.'
            );
        }

        DB::transaction(function () use (
            $item,
            $request
        ) {
            $item->update([
                'is_completed' => true,
                'completed_by' => $request->user()->id,
                'completed_at' => now(),
            ]);
        });

        return back()->with(
            'success',
            'Élément marqué comme terminé.'
        );
    }

    /**
     * Reopen a completed checklist item.
     */
    public function reopen(
        Request $request,
        string $id
    ): RedirectResponse {
        $item = $this->findScopedChecklistItem(
            $request,
            (int) $id
        );

        if (! $item->is_completed) {
            return back()->with(
                'success',
                'Cet élément est déjà ouvert.'
            );
        }

        DB::transaction(function () use ($item) {
            $item->update([
                'is_completed' => false,
                'completed_by' => null,
                'completed_at' => null,
            ]);
        });

        return back()->with(
            'success',
            'Élément rouvert avec succès.'
        );
    }

    /**
     * Delete a checklist item.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $item = $this->findScopedChecklistItem(
            $request,
            (int) $id
        );

        DB::transaction(function () use ($item) {
            $item->delete();
        });

        return back()->with(
            'success',
            'Élément supprimé de la checklist.'
        );
    }
}
