<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'department_id',
        'planning_year_id',
        'rollover_source_id',
        'created_by',
        'name',
        'code',
        'description',
        'start_date',
        'end_date',
        'status',
        'progress',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'progress' => 'integer',
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

    public function planningYear(): BelongsTo
    {
        return $this->belongsTo(PlanningYear::class);
    }

    public function rolloverSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollover_source_id');
    }

    public function rolloverCopies(): HasMany
    {
        return $this->hasMany(self::class, 'rollover_source_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function axes(): HasMany
    {
        return $this->hasMany(Axis::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class);
    }
}
