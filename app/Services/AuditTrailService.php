<?php

namespace App\Services;

use App\Models\Action;
use App\Models\ActionComment;
use App\Models\ActionPlan;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Axis;
use App\Models\ChecklistItem;
use App\Models\Comment;
use App\Models\Department;
use App\Models\GmailIntegration;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PlanningYear;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectChatAttachment;
use App\Models\ProjectChatMessage;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskPlanning;
use App\Models\TaskStatus;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use App\Models\WorkSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditTrailService
{
    /** @var array<int, string> */
    private const IGNORED_ATTRIBUTES = [
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
    ];

    /** @var array<int, string> */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'secret',
        'token',
        'credential',
        'api_key',
        'private_key',
    ];

    /** @var array<class-string<Model>, string> */
    private const SUBJECT_LABELS = [
        Action::class => 'Action',
        ActionComment::class => 'Commentaire d’action',
        ActionPlan::class => 'Plan d’action',
        Attachment::class => 'Pièce jointe',
        Axis::class => 'Axe',
        ChecklistItem::class => 'Élément de checklist',
        Comment::class => 'Commentaire',
        Department::class => 'Département',
        GmailIntegration::class => 'Connexion e-mail',
        Organization::class => 'Société',
        Permission::class => 'Permission',
        PlanningYear::class => 'Année de planification',
        Project::class => 'Projet',
        ProjectCategory::class => 'Catégorie de projet',
        ProjectChatAttachment::class => 'Pièce jointe du chat',
        ProjectChatMessage::class => 'Message du chat',
        Role::class => 'Rôle',
        Task::class => 'Tâche',
        TaskPlanning::class => 'Planification de tâche',
        TaskStatus::class => 'Statut de tâche',
        TimeSlot::class => 'Créneau',
        User::class => 'Utilisateur',
        WorkSession::class => 'Session de travail',
        WorkflowDefinition::class => 'Workflow',
        WorkflowEdge::class => 'Connexion de workflow',
        WorkflowNode::class => 'Étape de workflow',
    ];

    /** @param array<int, mixed> $payload */
    public function record(string $eventName, array $payload): void
    {
        $model = $payload[0] ?? null;
        $user = auth()->user();

        if (! $model instanceof Model || $model instanceof Activity || ! $user) {
            return;
        }

        $event = Str::after($eventName, 'eloquent.');
        $event = Str::before($event, ':');

        if (! in_array($event, ['created', 'updated', 'deleted'], true)) {
            return;
        }

        if ($event === 'updated' && Arr::except($model->getChanges(), self::IGNORED_ATTRIBUTES) === []) {
            return;
        }

        [$oldValues, $newValues] = $this->valuesFor($model, $event);

        $organizationId = $this->organizationId($model, $event);
        $subjectLabel = $this->subjectLabel($model);
        $recordLabel = $this->recordLabel($model);

        Activity::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user->getKey(),
            'subject_type' => $model->getMorphClass(),
            'subject_id' => $model->getKey(),
            'event' => $event,
            'description' => $this->description($subjectLabel, $recordLabel, $event),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'metadata' => [
                'subject_label' => $subjectLabel,
                'record_label' => $recordLabel,
                'route' => request()->route()?->getName(),
                'method' => request()->method(),
            ],
            'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 1000, ''),
        ]);
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function valuesFor(Model $model, string $event): array
    {
        if ($event === 'created') {
            return [[], $this->sanitize($model, $model->getAttributes())];
        }

        if ($event === 'deleted') {
            return [$this->sanitize($model, $model->getAttributes()), []];
        }

        $changes = Arr::except($model->getChanges(), self::IGNORED_ATTRIBUTES);
        $previous = $model->getPrevious();
        $oldValues = Arr::only($previous, array_keys($changes));

        return [
            $this->sanitize($model, $oldValues),
            $this->sanitize($model, $changes),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitize(Model $model, array $values): array
    {
        $values = Arr::except($values, [...self::IGNORED_ATTRIBUTES, ...$model->getHidden()]);

        return collect($values)
            ->reject(fn (mixed $value, string $key): bool => $this->isSensitiveKey($key))
            ->map(fn (mixed $value): mixed => $this->sanitizeValue($value))
            ->all();
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return collect($value)
                ->reject(fn (mixed $nestedValue, string|int $key): bool => is_string($key) && $this->isSensitiveKey($key))
                ->map(fn (mixed $nestedValue): mixed => $this->sanitizeValue($nestedValue))
                ->all();
        }

        if (is_string($value)) {
            return Str::limit($value, 2000, '…');
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = Str::lower($key);

        return collect(self::SENSITIVE_KEY_FRAGMENTS)
            ->contains(fn (string $fragment): bool => str_contains($normalizedKey, $fragment));
    }

    private function organizationId(Model $model, string $event): ?int
    {
        if ($model instanceof Organization) {
            return $event === 'deleted' ? null : (int) $model->getKey();
        }

        $organizationId = $model->getAttribute('organization_id') ?: session('current_organization_id');

        return $organizationId ? (int) $organizationId : null;
    }

    private function subjectLabel(Model $model): string
    {
        return self::SUBJECT_LABELS[$model::class] ?? Str::headline(class_basename($model));
    }

    private function recordLabel(Model $model): string
    {
        foreach (['name', 'title', 'label', 'code', 'email'] as $attribute) {
            $value = trim((string) $model->getAttribute($attribute));

            if ($value !== '') {
                return Str::limit($value, 180, '…');
            }
        }

        return '#'.$model->getKey();
    }

    private function description(string $subjectLabel, string $recordLabel, string $event): string
    {
        $verb = match ($event) {
            'created' => 'créé',
            'updated' => 'modifié',
            'deleted' => 'supprimé',
        };

        return $subjectLabel.' « '.$recordLabel.' » '.$verb.'.';
    }
}
