<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'department_id',
        'action_plan_id',
        'axis_id',
        'project_category_id',
        'manager_id',
        'created_by',
        'creation_token',
        'name',
        'code',
        'description',
        'start_date',
        'due_date',
        'status',
        'priority',
        'position',
        'progress',
        'is_private',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'progress' => 'integer',
            'position' => 'integer',
            'is_private' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'project_department')->withTimestamps();
    }

    public function actionPlan(): BelongsTo
    {
        return $this->belongsTo(ActionPlan::class);
    }

    public function axis(): BelongsTo
    {
        return $this->belongsTo(Axis::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class, 'project_category_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function projectMembers(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'project_members'
        )->withPivot([
            'role',
            'joined_at',
        ])->withTimestamps();
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class);
    }

    public function recalculateProgress(): void
    {
        $progress = (int) round((float) ($this->actions()->avg('progress') ?? 0));

        $this->forceFill(['progress' => $progress])->saveQuietly();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function chatMessages()
    {
        return $this->hasMany(ProjectChatMessage::class);
    }

    public function chatReads()
    {
        return $this->hasMany(ProjectChatRead::class);
    }

    public function chatMembers()
    {
        return $this->belongsToMany(
            User::class,
            'project_members',
            'project_id',
            'user_id'
        )->withPivot('role', 'joined_at')->withTimestamps();
    }
}
