<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class WorkContextDepartmentService
{
    /** @return Collection<int, Department> */
    public function accessibleDepartments(User $user, int $organizationId): Collection
    {
        if ($this->isDirectorGeneral($user, $organizationId)) {
            return Department::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        return $user->departments()
            ->where('departments.organization_id', $organizationId)
            ->wherePivot('is_active', true)
            ->orderByDesc('department_members.is_primary')
            ->orderBy('departments.name')
            ->get();
    }

    public function isDirectorGeneral(User $user, int $organizationId): bool
    {
        return $user->roles()
            ->where('user_roles.organization_id', $organizationId)
            ->where('roles.slug', 'director')
            ->where('roles.is_active', true)
            ->exists();
    }

    public function canAccessDepartment(User $user, int $organizationId, int $departmentId): bool
    {
        return $this->accessibleDepartments($user, $organizationId)
            ->contains('id', $departmentId);
    }
}
