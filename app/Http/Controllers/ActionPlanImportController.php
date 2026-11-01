<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportActionPlanRequest;
use App\Models\Department;
use App\Models\Organization;
use App\Models\PlanningYear;
use App\Services\ActionPlanImportService;
use App\Services\WorkContextDepartmentService;
use Illuminate\Http\RedirectResponse;

class ActionPlanImportController extends Controller
{
    public function __construct(
        private readonly WorkContextDepartmentService $departmentAccess,
    ) {}

    public function store(
        ImportActionPlanRequest $request,
        ActionPlanImportService $importer,
    ): RedirectResponse {
        $organization = $request->user()
            ->organizations()
            ->wherePivot('is_active', true)
            ->whereKey((int) session('current_organization_id'))
            ->firstOrFail();
        $department = $this->departmentAccess
            ->accessibleDepartments($request->user(), $organization->id)
            ->firstWhere('id', (int) session('current_department_id'));
        abort_unless($department instanceof Department, 403);
        abort_unless($organization instanceof Organization, 403);

        $planningYear = PlanningYear::resolveForOrganization($organization->id, now());
        $result = $importer->import(
            $request->file('file'),
            $organization,
            $department,
            $planningYear,
            $request->user(),
        );

        return redirect()->route('action-plans.index')->with(
            'success',
            "Import terminé : {$result['plans']} plan(s), {$result['axes']} axe(s), {$result['projects']} projet(s) et {$result['actions']} action(s) créés.",
        );
    }
}
