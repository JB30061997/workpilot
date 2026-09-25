<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WorkSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'department_id',
        'project_id',
        'created_by',
        'name',
        'description',
        'start_date',
        'end_date',
        'visibility',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sessionMembers(): HasMany
    {
        return $this->hasMany(SessionMember::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'session_members'
        )->withPivot([
            'role',
            'joined_at',
        ])->withTimestamps();
    }

    public function timeSlots(): HasMany
    {
        return $this->hasMany(TimeSlot::class)
            ->orderBy('start_at');
    }
}