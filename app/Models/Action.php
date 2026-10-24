<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Action extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'action_plan_id',
        'axis_id',
        'owner_id',
        'created_by',
        'title',
        'description',
        'start_date',
        'due_date',
        'actual_start_date',
        'actual_end_date',
        'status',
        'priority',
        'progress',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'progress' => 'integer',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Action $action): void {
            $action->project?->recalculateProgress();
        });

        static::deleted(function (Action $action): void {
            $action->project?->recalculateProgress();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function actionPlan(): BelongsTo
    {
        return $this->belongsTo(ActionPlan::class);
    }

    public function axis(): BelongsTo
    {
        return $this->belongsTo(Axis::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ActionComment::class);
    }
}
