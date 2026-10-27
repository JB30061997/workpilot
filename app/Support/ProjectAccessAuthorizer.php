<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;

class ProjectAccessAuthorizer
{
    public function __construct(
        private readonly CurrentDepartmentAuthorizer $departmentAuthorizer
    ) {}

    public function canCreateProject(User $user, int $organizationId, int $departmentId): bool
    {
        $belongsToOrganization = $user->organizations()
            ->whereKey($organizationId)
            ->wherePivot('is_active', true)
            ->exists();

        if (! $belongsToOrganization) {
            return false;
        }

        return $user->departments()
            ->whereKey($departmentId)
            ->where('departments.organization_id', $organizationId)
            ->wherePivot('is_active', true)
            ->exists();
    }

    public function canManageProject(
        User $user,
        Project $project,
        int $organizationId,
        int $departmentId
    ): bool {
        if (
            (int) $project->organization_id !== $organizationId
            || ! $this->belongsToDepartment($project, $departmentId)
        ) {
            return false;
        }

        return (int) $project->manager_id === (int) $user->id
            || $project->departmentResponsibles()
                ->where('department_id', $departmentId)
                ->where('user_id', $user->id)
                ->exists()
            || $project->projectMembers()
                ->where('user_id', $user->id)
                ->where('role', 'manager')
                ->exists()
            || $this->departmentAuthorizer->canManageAccess(
                $user,
                $organizationId,
                $departmentId,
            );
    }

    public function canManageProjectActions(
        User $user,
        Project $project,
        int $organizationId,
        int $departmentId
    ): bool {
        if (
            (int) $project->organization_id !== $organizationId
            || ! $this->belongsToDepartment($project, $departmentId)
        ) {
            return false;
        }

        return (int) $project->created_by === (int) $user->id
            || $this->canManageProject($user, $project, $organizationId, $departmentId);
    }

    public function authorizeProjectCreation(User $user, int $organizationId, int $departmentId): void
    {
        abort_unless(
            $this->canCreateProject($user, $organizationId, $departmentId),
            403,
            'Vous devez être membre actif du département pour créer un projet.'
        );
    }

    public function authorizeProjectManagement(
        User $user,
        Project $project,
        int $organizationId,
        int $departmentId
    ): void {
        abort_unless(
            $this->canManageProject($user, $project, $organizationId, $departmentId),
            403,
            'Seuls le directeur du département et le responsable du projet peuvent effectuer cette action.'
        );
    }

    public function authorizeProjectActionManagement(
        User $user,
        Project $project,
        int $organizationId,
        int $departmentId
    ): void {
        abort_unless(
            $this->canManageProjectActions($user, $project, $organizationId, $departmentId),
            403,
            'Seuls le directeur du département, le responsable et le créateur du projet peuvent gérer ses actions.'
        );
    }

    private function belongsToDepartment(Project $project, int $departmentId): bool
    {
        return (int) $project->department_id === $departmentId
            || $project->departments()->whereKey($departmentId)->exists();
    }
}
