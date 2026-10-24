<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommentController extends Controller
{
    private const DEFAULT_PER_PAGE = 30;

    private const MAX_PER_PAGE = 100;

    /**
     * Resolve the active organization.
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
     * Resolve the current WorkPilot context.
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
     * Find a task belonging strictly to
     * the current organization and department.
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
     * Find a comment through a task belonging to
     * the current organization and department.
     */
    private function findScopedComment(
        Request $request,
        int $commentId
    ): Comment {
        $context = $this->getContext($request);

        return Comment::query()
            ->whereKey($commentId)
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
     * Display comments belonging to tasks
     * of the current service.
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

            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'parent_id' => [
                'nullable',
                'integer',
                'exists:comments,id',
            ],

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'between:1,'.self::MAX_PER_PAGE,
            ],
        ]);

        /*
         * A task supplied as a filter must belong
         * to the current organization/service.
         */
        if (! empty($validated['task_id'])) {
            $this->findScopedTask(
                $request,
                (int) $validated['task_id']
            );
        }

        /*
         * A parent supplied as a filter must also
         * belong to the current organization/service.
         */
        if (! empty($validated['parent_id'])) {
            $this->findScopedComment(
                $request,
                (int) $validated['parent_id']
            );
        }

        $comments = Comment::query()
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
                ! empty($validated['user_id']),
                fn ($query) => $query->where(
                    'user_id',
                    $validated['user_id']
                )
            )

            ->when(
                ! empty($validated['parent_id']),
                fn ($query) => $query->where(
                    'parent_id',
                    $validated['parent_id']
                )
            )

            ->when(
                ! empty($validated['search']),
                fn ($query) => $query->where(
                    'content',
                    'like',
                    '%'.trim($validated['search']).'%'
                )
            )

            ->with([
                'user:id,name,email',
                'task:id,title,organization_id,department_id',
            ])

            ->withCount([
                'replies',
                'attachments',
            ])

            ->latest('created_at')
            ->latest('id')

            ->paginate(
                $validated['per_page']
                    ?? self::DEFAULT_PER_PAGE
            )
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

            'comments' => $comments,
        ]);
    }

    /**
     * Store a new comment.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'task_id' => [
                'required',
                'integer',
                'exists:tasks,id',
            ],

            'parent_id' => [
                'nullable',
                'integer',
                'exists:comments,id',
            ],

            'content' => [
                'required',
                'string',
                'max:10000',
            ],
        ], [
            'task_id.required' => 'La tâche est obligatoire.',

            'task_id.exists' => 'La tâche sélectionnée est introuvable.',

            'parent_id.exists' => 'Le commentaire parent est introuvable.',

            'content.required' => 'Le contenu du commentaire est obligatoire.',

            'content.max' => 'Le commentaire ne peut pas dépasser 10 000 caractères.',
        ]);

        $task = $this->findScopedTask(
            $request,
            (int) $data['task_id']
        );

        $parent = null;

        if (! empty($data['parent_id'])) {
            $parent = $this->findScopedComment(
                $request,
                (int) $data['parent_id']
            );

            /*
             * A reply must belong to the same task.
             */
            if ((int) $parent->task_id !== (int) $task->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Le commentaire parent n’appartient pas à cette tâche.',
                ]);
            }
        }

        DB::transaction(function () use (
            $request,
            $task,
            $parent,
            $data
        ) {
            Comment::create([
                'task_id' => $task->id,
                'user_id' => $request->user()->id,

                'parent_id' => $parent?->id,

                'content' => trim($data['content']),

                'is_edited' => false,
            ]);
        });

        return back()->with(
            'success',
            'Commentaire ajouté avec succès.'
        );
    }

    /**
     * Display one comment.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $comment = $this->findScopedComment(
            $request,
            (int) $id
        );

        $comment->load([
            'user:id,name,email',

            'task:id,title,organization_id,department_id',

            'parent.user:id,name,email',

            'replies' => function ($query) {
                $query
                    ->with([
                        'user:id,name,email',
                    ])
                    ->oldest('created_at')
                    ->oldest('id');
            },

            'attachments',
        ]);

        return response()->json([
            'comment' => $comment,
        ]);
    }

    /**
     * Update a comment.
     *
     * Only the author may edit their own comment.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $comment = $this->findScopedComment(
            $request,
            (int) $id
        );

        abort_if(
            (int) $comment->user_id
                !== (int) $request->user()->id,
            403,
            'Vous ne pouvez modifier que vos propres commentaires.'
        );

        $data = $request->validate([
            'content' => [
                'required',
                'string',
                'max:10000',
            ],
        ], [
            'content.required' => 'Le contenu du commentaire est obligatoire.',

            'content.max' => 'Le commentaire ne peut pas dépasser 10 000 caractères.',
        ]);

        $newContent = trim($data['content']);

        if ($newContent === '') {
            throw ValidationException::withMessages([
                'content' => 'Le contenu du commentaire ne peut pas être vide.',
            ]);
        }

        DB::transaction(function () use (
            $comment,
            $newContent
        ) {
            /*
             * Mark the comment as edited only if
             * its content actually changed.
             */
            if ($comment->content !== $newContent) {
                $comment->update([
                    'content' => $newContent,
                    'is_edited' => true,
                ]);
            }
        });

        return back()->with(
            'success',
            'Commentaire mis à jour avec succès.'
        );
    }

    /**
     * Delete a comment.
     *
     * Only the author may delete their own comment.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $comment = $this->findScopedComment(
            $request,
            (int) $id
        );

        abort_if(
            (int) $comment->user_id
                !== (int) $request->user()->id,
            403,
            'Vous ne pouvez supprimer que vos propres commentaires.'
        );

        /*
         * Do not silently delete a discussion tree.
         */
        if ($comment->replies()->exists()) {
            throw ValidationException::withMessages([
                'comment' => 'Ce commentaire contient des réponses et ne peut pas être supprimé.',
            ]);
        }

        /*
         * Attachments are intentionally protected too.
         * They should first be removed through AttachmentController.
         */
        if ($comment->attachments()->exists()) {
            throw ValidationException::withMessages([
                'comment' => 'Ce commentaire contient des pièces jointes. Supprimez-les avant de supprimer le commentaire.',
            ]);
        }

        DB::transaction(function () use ($comment) {
            $comment->delete();
        });

        return back()->with(
            'success',
            'Commentaire supprimé avec succès.'
        );
    }
}
