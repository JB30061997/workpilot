<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkSession;

class WorkSessionPolicy
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
    public function view(User $user, WorkSession $workSession): bool
    {
        if (! $this->belongsToDepartment($user, $workSession)) {
            return false;
        }

        return $workSession->visibility === 'department'
            || (int) $workSession->created_by === (int) $user->id
            || $workSession->members()->whereKey($user->id)->exists();
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
    public function update(User $user, WorkSession $workSession): bool
    {
        return $this->canManage($user, $workSession);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkSession $workSession): bool
    {
        return $this->canManage($user, $workSession);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, WorkSession $workSession): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, WorkSession $workSession): bool
    {
        return false;
    }

    private function belongsToDepartment(User $user, WorkSession $workSession): bool
    {
        return $workSession->department_id !== null
            && $user->departments()
                ->whereKey($workSession->department_id)
                ->wherePivot('is_active', true)
                ->exists();
    }

    private function canManage(User $user, WorkSession $workSession): bool
    {
        if (! $this->belongsToDepartment($user, $workSession)) {
            return false;
        }

        return (int) $workSession->created_by === (int) $user->id
            || $workSession->members()
                ->whereKey($user->id)
                ->wherePivot('role', 'owner')
                ->exists();
    }
}
