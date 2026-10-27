<?php

namespace App\Http\Middleware;

use App\Services\WorkContextDepartmentService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly WorkContextDepartmentService $departmentAccess,
    ) {}

    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
            ],
            'workContext' => fn (): ?array => $user ? $this->workContext($request) : null,
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
                'open_project_id' => fn (): ?int => $request->session()->get('open_project_id'),
            ],
        ];
    }

    /**
     * @return array{
     *     organization_id: int|null,
     *     department_id: int|null,
     *     organizations: array<int, array{id: int, name: string}>,
     *     departments: array<int, array{id: int, name: string, code: string|null}>
     * }
     */
    private function workContext(Request $request): array
    {
        $user = $request->user();

        $organizations = $user->organizations()
            ->wherePivot('is_active', true)
            ->orderBy('organizations.name')
            ->get(['organizations.id', 'organizations.name']);

        $organizationId = (int) session('current_organization_id');

        if (! $organizations->contains('id', $organizationId)) {
            $organizationId = (int) ($organizations->first()?->id ?? 0);
        }

        $departments = $this->departmentAccess
            ->accessibleDepartments($user, $organizationId);

        $departmentId = (int) session('current_department_id');

        if (! $departments->contains('id', $departmentId)) {
            $departmentId = (int) ($departments->first()?->id ?? 0);
        }

        return [
            'organization_id' => $organizationId ?: null,
            'department_id' => $departmentId ?: null,
            'organizations' => $organizations->map->only(['id', 'name'])->values()->all(),
            'departments' => $departments->map->only(['id', 'name', 'code'])->values()->all(),
        ];
    }
}
