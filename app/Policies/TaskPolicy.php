<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
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
    public function view(User $user, Task $task): bool
    {
        if (! $this->belongsToDepartment($user, $task)) {
            return false;
        }

        return $task->workSession === null || $user->can('view', $task->workSession);
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
    public function update(User $user, Task $task): bool
    {
        return $this->view($user, $task)
            && (int) $task->created_by === (int) $user->id;
    }

    /**
     * Determine whether the user can move a visible task to another status.
     */
    public function updateStatus(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Task $task): bool
    {
        if (! $this->view($user, $task)) {
            return false;
        }

        return $task->workSession === null
            || (int) $task->created_by === (int) $user->id
            || $task->workSession->members()
                ->whereKey($user->id)
                ->wherePivotIn('role', ['owner', 'member'])
                ->exists();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Task $task): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Task $task): bool
    {
        return false;
    }

    private function belongsToDepartment(User $user, Task $task): bool
    {
        return $task->department_id !== null
            && $user->departments()
                ->whereKey($task->department_id)
                ->wherePivot('is_active', true)
                ->exists();
    }
}
