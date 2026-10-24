<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\ProjectCategory;
use App\Models\Role;
use App\Models\TaskStatus;
use App\Support\CurrentDepartmentAuthorizer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReferentialController extends Controller
{
    public function __construct(
        private readonly CurrentDepartmentAuthorizer $departmentAuthorizer
    ) {}

    public function index(Request $request): Response
    {
        $organizations = $request->user()
            ->organizations()
            ->wherePivot('is_active', true)
            ->withCount(['departments', 'users'])
            ->orderBy('name')
            ->get();

        abort_if($organizations->isEmpty(), 403, 'Aucune société accessible pour votre compte.');

        $administrativeOrganizationIds = $request->user()
            ->roles()
            ->whereIn('roles.slug', ['administrator', 'director'])
            ->pluck('user_roles.organization_id');

        $organizations->each(function (Organization $item) use ($administrativeOrganizationIds): void {
            $isOwner = (bool) $item->pivot->is_owner;
            $item->setAttribute('can_update', $isOwner || $administrativeOrganizationIds->contains($item->id));
            $item->setAttribute('can_delete', $isOwner);
        });

        $organizationId = (int) session('current_organization_id', $organizations->first()->id);
        $organization = $organizations->firstWhere('id', $organizationId) ?? $organizations->first();
        $canManageAccess = $this->departmentAuthorizer->canManageAccess(
            $request->user(),
            $organization->id,
            (int) session('current_department_id'),
        );

        if ((int) session('current_organization_id') !== (int) $organization->id) {
            session(['current_organization_id' => $organization->id]);
        }

        return Inertia::render('Referential/Index', [
            'organization' => $organization->only(['id', 'name']),
            'organizations' => $organizations,
            'departments' => Department::query()
                ->where('organization_id', $organization->id)
                ->with('parent:id,name')
                ->withCount(['users', 'projects'])
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'projectCategories' => ProjectCategory::query()
                ->where('organization_id', $organization->id)
                ->with('manager:id,name,email')
                ->withCount('projects')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'organizationUsers' => $organization->users()
                ->wherePivot('is_active', true)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email']),
            'taskStatuses' => TaskStatus::query()
                ->where('organization_id', $organization->id)
                ->withCount('tasks')
                ->orderBy('position')
                ->orderBy('name')
                ->get(),
            'roles' => Role::query()
                ->where('organization_id', $organization->id)
                ->with('permissions:id,name,module')
                ->withCount('users')
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->get(),
            'permissions' => Permission::query()
                ->withCount('roles')
                ->orderBy('module')
                ->orderBy('name')
                ->get(),
            'activeTab' => in_array($request->string('tab')->toString(), ['statuses', 'organizations', 'departments', 'project-categories', 'roles', 'permissions'], true)
                ? $request->string('tab')->toString()
                : 'statuses',
            'canManageAccess' => $canManageAccess,
        ]);
    }
}
