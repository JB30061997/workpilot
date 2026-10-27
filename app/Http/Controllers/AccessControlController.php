<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DefaultRoleProvisioner;
use App\Support\CurrentDepartmentAuthorizer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccessControlController extends Controller
{
    public function __construct(
        private readonly CurrentDepartmentAuthorizer $departmentAuthorizer,
        private readonly DefaultRoleProvisioner $defaultRoleProvisioner,
    ) {}

    public function index(Request $request): Response
    {
        $organization = $this->currentOrganization($request);
        $this->defaultRoleProvisioner->provision($organization);

        return Inertia::render('AccessControl/Index', [
            'organization' => $organization->only(['id', 'name']),
            'roles' => Role::query()
                ->whereBelongsTo($organization)
                ->with(['permissions:id,name,slug,module'])
                ->withCount(['permissions', 'users'])
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->get(),
            'permissions' => Permission::query()
                ->withCount('roles')
                ->orderBy('module')
                ->orderBy('name')
                ->get(),
            'departments' => Department::query()
                ->where('organization_id', $organization->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'members' => User::query()
                ->whereHas('organizations', fn ($query) => $query
                    ->whereKey($organization->id)
                    ->where('organization_members.is_active', true))
                ->with([
                    'departments' => fn ($query) => $query
                        ->where('departments.organization_id', $organization->id)
                        ->where('department_members.is_active', true)
                        ->orderByDesc('department_members.is_primary')
                        ->orderBy('departments.name'),
                    'roles' => fn ($query) => $query
                        ->where('user_roles.organization_id', $organization->id),
                ])
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'canManageAccess' => $this->departmentAuthorizer->canManageAccess(
                $request->user(),
                $organization->id,
                (int) session('current_department_id'),
            ),
        ]);
    }

    private function currentOrganization(Request $request): Organization
    {
        $organizations = $request->user()
            ->organizations()
            ->wherePivot('is_active', true)
            ->orderBy('organizations.name')
            ->get();

        abort_if($organizations->isEmpty(), 403, 'Aucune société accessible pour votre compte.');

        $organizationId = (int) session('current_organization_id');
        $organization = $organizations->firstWhere('id', $organizationId) ?? $organizations->first();

        if ($organizationId !== (int) $organization->id) {
            session(['current_organization_id' => $organization->id]);
        }

        return $organization;
    }
}
