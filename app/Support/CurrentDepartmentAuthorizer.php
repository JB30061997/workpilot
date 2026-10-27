<?php

namespace App\Support;

use App\Models\User;

class CurrentDepartmentAuthorizer
{
    public function canManageAccess(
        User $user,
        int $organizationId,
        int $departmentId
    ): bool {
        $isDirectorGeneral = $user->roles()
            ->where('user_roles.organization_id', $organizationId)
            ->where('roles.slug', 'director')
            ->where('roles.is_active', true)
            ->exists();

        if ($isDirectorGeneral) {
            return true;
        }

        $managesDepartment = $user->departmentMemberships()
            ->where('department_id', $departmentId)
            ->where('is_manager', true)
            ->where('is_active', true)
            ->whereHas('department', fn ($query) => $query->where('organization_id', $organizationId))
            ->exists();

        if (! $managesDepartment) {
            return false;
        }

        return $user->roles()
            ->where('user_roles.organization_id', $organizationId)
            ->whereIn('roles.slug', ['administrator', 'director', 'deputy-director', 'department-manager'])
            ->exists();
    }

    public function authorizeAccessManagement(
        User $user,
        int $organizationId,
        int $departmentId
    ): void {
        abort_unless(
            $this->canManageAccess($user, $organizationId, $departmentId),
            403,
            'Seul le directeur du département peut gérer les utilisateurs, rôles et permissions.'
        );
    }

    public function canManageGmail(
        User $user,
        int $organizationId,
        int $departmentId
    ): bool {
        return $user->departmentMemberships()
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->whereHas('department', fn ($query) => $query->where('organization_id', $organizationId))
            ->exists();
    }

    public function authorizeGmailManagement(
        User $user,
        int $organizationId,
        int $departmentId
    ): void {
        abort_unless(
            $this->canManageGmail($user, $organizationId, $departmentId),
            403,
            'Vous devez être membre actif du service pour gérer la boîte Gmail.'
        );
    }
}
