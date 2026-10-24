<?php

namespace App\Policies;

use App\Models\TaskPlanning;
use App\Models\User;
use App\Support\CurrentDepartmentAuthorizer;

class TaskPlanningPolicy
{
    public function __construct(
        private readonly CurrentDepartmentAuthorizer $departmentAuthorizer,
    ) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->departmentMemberships()->where('is_active', true)->exists();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TaskPlanning $taskPlanning): bool
    {
        return $user->can('view', $taskPlanning->task);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->departmentMemberships()->where('is_active', true)->exists();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TaskPlanning $taskPlanning): bool
    {
        return $this->view($user, $taskPlanning)
            && ((int) $taskPlanning->created_by === (int) $user->id
                || $user->can('update', $taskPlanning->task)
                || $this->departmentAuthorizer->canManageAccess(
                    $user,
                    (int) $taskPlanning->organization_id,
                    (int) $taskPlanning->department_id,
                ));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TaskPlanning $taskPlanning): bool
    {
        return $this->update($user, $taskPlanning);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, TaskPlanning $taskPlanning): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, TaskPlanning $taskPlanning): bool
    {
        return false;
    }
}
