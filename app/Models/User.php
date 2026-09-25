<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // =========================================================
    // ORGANIZATIONS
    // =========================================================

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(
            Organization::class,
            'organization_members'
        )
        ->withPivot([
            'job_title',
            'status',
            'joined_at',
            'is_owner',
            'is_active',
        ])
        ->withTimestamps();
    }

    // =========================================================
    // DEPARTMENTS
    // =========================================================

    public function departmentMemberships(): HasMany
    {
        return $this->hasMany(DepartmentMember::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'department_members'
        )
        ->withPivot([
            'is_manager',
            'is_primary',
            'is_active',
            'joined_at',
        ])
        ->withTimestamps();
    }

    // =========================================================
    // ROLES & PERMISSIONS
    // =========================================================

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'user_roles'
        )
        ->withPivot('organization_id')
        ->withTimestamps();
    }

    // =========================================================
    // ACTION PLANS
    // =========================================================

    public function createdActionPlans(): HasMany
    {
        return $this->hasMany(ActionPlan::class, 'created_by');
    }

    // =========================================================
    // PROJECTS
    // =========================================================

    public function managedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'manager_id');
    }

    public function createdProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'created_by');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(
            Project::class,
            'project_members'
        )
        ->withPivot([
            'role',
            'joined_at',
        ])
        ->withTimestamps();
    }

    // =========================================================
    // ACTIONS
    // =========================================================

    public function ownedActions(): HasMany
    {
        return $this->hasMany(Action::class, 'owner_id');
    }

    public function createdActions(): HasMany
    {
        return $this->hasMany(Action::class, 'created_by');
    }

    // =========================================================
    // TASKS
    // =========================================================

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function assignedTasks(): BelongsToMany
    {
        return $this->belongsToMany(
            Task::class,
            'task_assignees'
        )
        ->withPivot([
            'assigned_by',
            'assigned_at',
        ])
        ->withTimestamps();
    }

    // =========================================================
    // COMMENTS
    // =========================================================

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    // =========================================================
    // WORK SESSIONS
    // =========================================================

    public function createdWorkSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class, 'created_by');
    }

    public function workSessions(): BelongsToMany
    {
        return $this->belongsToMany(
            WorkSession::class,
            'session_members'
        )
        ->withPivot([
            'role',
            'joined_at',
        ])
        ->withTimestamps();
    }

    // =========================================================
    // TIME SLOTS
    // =========================================================

    public function createdTimeSlots(): HasMany
    {
        return $this->hasMany(TimeSlot::class, 'created_by');
    }

    public function timeSlots(): BelongsToMany
    {
        return $this->belongsToMany(
            TimeSlot::class,
            'time_slot_members'
        )->withTimestamps();
    }

    // =========================================================
    // ACTIVITIES
    // =========================================================

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }
}