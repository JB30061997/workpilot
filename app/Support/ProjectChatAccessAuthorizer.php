<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProjectChatAccessAuthorizer
{
    /** @return Collection<int, User> */
    public function recipients(Project $project): Collection
    {
        $departmentIds = $project->departments()->pluck('departments.id');

        if ($project->department_id !== null) {
            $departmentIds->push($project->department_id);
        }

        $departmentIds = $departmentIds->unique()->values();
        $userIds = $project->members()->pluck('users.id');

        if ($project->manager_id !== null) {
            $userIds->push($project->manager_id);
        }

        if ($departmentIds->isNotEmpty()) {
            $directorIds = DB::table('department_members')
                ->join('user_roles', function ($join) use ($project): void {
                    $join->on('user_roles.user_id', '=', 'department_members.user_id')
                        ->where('user_roles.organization_id', '=', $project->organization_id);
                })
                ->join('roles', function ($join): void {
                    $join->on('roles.id', '=', 'user_roles.role_id')
                        ->where('roles.slug', '=', 'department-manager')
                        ->where('roles.is_active', '=', true);
                })
                ->whereIn('department_members.department_id', $departmentIds)
                ->where('department_members.is_manager', true)
                ->where('department_members.is_active', true)
                ->pluck('department_members.user_id');

            $userIds = $userIds->merge($directorIds);
        }

        return User::query()
            ->whereIn('id', $userIds->unique())
            ->whereNotNull('email')
            ->get();
    }

    public function accessibleProjects(User $user): Builder
    {
        $directedOrganizationIds = DB::table('roles')
            ->join('user_roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->where('roles.slug', 'department-manager')
            ->where('roles.is_active', true)
            ->select('user_roles.organization_id');

        $directedDepartmentIds = DB::table('department_members')
            ->where('user_id', $user->id)
            ->where('is_manager', true)
            ->where('is_active', true)
            ->select('department_id');

        return Project::query()
            ->where(function (Builder $query) use ($user, $directedOrganizationIds, $directedDepartmentIds): void {
                $query
                    ->whereHas('projectMembers', fn (Builder $memberQuery) => $memberQuery
                        ->where('user_id', $user->id))
                    ->orWhere(function (Builder $directorQuery) use ($directedOrganizationIds, $directedDepartmentIds): void {
                        $directorQuery
                            ->whereIn('organization_id', $directedOrganizationIds)
                            ->where(function (Builder $departmentQuery) use ($directedDepartmentIds): void {
                                $departmentQuery
                                    ->whereIn('department_id', $directedDepartmentIds)
                                    ->orWhereHas('departments', fn (Builder $projectDepartmentQuery) => $projectDepartmentQuery
                                        ->whereIn('departments.id', $directedDepartmentIds));
                            });
                    });
            });
    }

    public function canAccess(User $user, Project $project): bool
    {
        return $this->accessibleProjects($user)
            ->whereKey($project->id)
            ->exists();
    }

    public function authorize(User $user, Project $project): void
    {
        abort_unless(
            $this->canAccess($user, $project),
            403,
            'Vous ne pouvez pas accéder à la discussion de ce projet.'
        );
    }
}
