<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\ActionComment;
use App\Models\Department;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActionCommentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action_id' => ['required', 'integer', 'exists:actions,id'],
            'content' => ['required', 'string', 'max:10000'],
        ], [
            'action_id.required' => 'L’action est obligatoire.',
            'action_id.exists' => 'L’action sélectionnée est introuvable.',
            'content.required' => 'Le commentaire est obligatoire.',
            'content.max' => 'Le commentaire ne peut pas dépasser 10 000 caractères.',
        ]);

        $context = $this->getContext($request);
        $action = $this->findScopedAction(
            (int) $data['action_id'],
            $context['organization'],
            $context['department'],
        );
        $content = trim($data['content']);

        if ($content === '') {
            throw ValidationException::withMessages([
                'content' => 'Le commentaire ne peut pas être vide.',
            ]);
        }

        DB::transaction(function () use ($action, $content, $request) {
            ActionComment::query()->create([
                'action_id' => $action->id,
                'user_id' => $request->user()->id,
                'content' => $content,
            ]);
        });

        return back()->with('success', 'Commentaire ajouté avec succès.');
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $comment = $this->findScopedComment($request, (int) $id);

        abort_if(
            (int) $comment->user_id !== (int) $request->user()->id,
            403,
            'Vous ne pouvez modifier que vos propres commentaires.',
        );

        $data = $request->validate([
            'content' => ['required', 'string', 'max:10000'],
        ], [
            'content.required' => 'Le commentaire est obligatoire.',
            'content.max' => 'Le commentaire ne peut pas dépasser 10 000 caractères.',
        ]);
        $content = trim($data['content']);

        if ($content === '') {
            throw ValidationException::withMessages([
                'content' => 'Le commentaire ne peut pas être vide.',
            ]);
        }

        DB::transaction(fn () => $comment->update(['content' => $content]));

        return back()->with('success', 'Commentaire modifié avec succès.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $comment = $this->findScopedComment($request, (int) $id);

        abort_if(
            (int) $comment->user_id !== (int) $request->user()->id,
            403,
            'Vous ne pouvez supprimer que vos propres commentaires.',
        );

        DB::transaction(fn () => $comment->delete());

        return back()->with('success', 'Commentaire supprimé avec succès.');
    }

    /**
     * @return array{organization: Organization, department: Department}
     */
    private function getContext(Request $request): array
    {
        $organization = $request->user()
            ->organizations()
            ->wherePivot('is_active', true)
            ->when(
                session('current_organization_id'),
                fn ($query, $organizationId) => $query->whereKey($organizationId),
            )
            ->firstOrFail();

        $department = $request->user()
            ->departments()
            ->where('departments.organization_id', $organization->id)
            ->wherePivot('is_active', true)
            ->when(
                session('current_department_id'),
                fn ($query, $departmentId) => $query->whereKey($departmentId),
            )
            ->firstOrFail();

        return [
            'organization' => $organization,
            'department' => $department,
        ];
    }

    private function findScopedAction(
        int $actionId,
        Organization $organization,
        Department $department,
    ): Action {
        return Action::query()
            ->whereKey($actionId)
            ->where('organization_id', $organization->id)
            ->where(function ($query) use ($department) {
                $query
                    ->whereHas(
                        'project',
                        fn ($projectQuery) => $projectQuery->where('department_id', $department->id),
                    )
                    ->orWhereHas(
                        'actionPlan',
                        fn ($planQuery) => $planQuery->where('department_id', $department->id),
                    );
            })
            ->firstOrFail();
    }

    private function findScopedComment(Request $request, int $commentId): ActionComment
    {
        $context = $this->getContext($request);

        return ActionComment::query()
            ->whereKey($commentId)
            ->whereHas('action', function ($actionQuery) use ($context) {
                $actionQuery
                    ->where('organization_id', $context['organization']->id)
                    ->where(function ($query) use ($context) {
                        $query
                            ->whereHas(
                                'project',
                                fn ($projectQuery) => $projectQuery->where('department_id', $context['department']->id),
                            )
                            ->orWhereHas(
                                'actionPlan',
                                fn ($planQuery) => $planQuery->where('department_id', $context['department']->id),
                            );
                    });
            })
            ->firstOrFail();
    }
}
