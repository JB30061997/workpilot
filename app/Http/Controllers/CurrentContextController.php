<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CurrentContextController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $organization = $request->user()
            ->organizations()
            ->wherePivot('is_active', true)
            ->whereKey($data['organization_id'])
            ->first();

        if (! $organization) {
            throw ValidationException::withMessages([
                'organization_id' => 'La société sélectionnée n’est pas accessible.',
            ]);
        }

        $departments = $request->user()
            ->departments()
            ->where('departments.organization_id', $organization->id)
            ->wherePivot('is_active', true)
            ->orderByDesc('department_members.is_primary')
            ->orderBy('departments.name');

        $department = isset($data['department_id'])
            ? (clone $departments)->whereKey($data['department_id'])->first()
            : $departments->first();

        if (! $department) {
            throw ValidationException::withMessages([
                'department_id' => 'Le service sélectionné n’est pas accessible dans cette société.',
            ]);
        }

        session([
            'current_organization_id' => $organization->id,
            'current_department_id' => $department->id,
        ]);

        return back()->with('success', 'Contexte de travail mis à jour.');
    }
}
