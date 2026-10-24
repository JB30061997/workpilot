<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectCategoryController extends Controller
{
    private function getCurrentOrganization(Request $request): Organization
    {
        $organization = $request->user()->organizations()
            ->wherePivot('is_active', true)
            ->when(
                session('current_organization_id'),
                fn ($query, $organizationId) => $query->whereKey($organizationId)
            )
            ->first();

        abort_if(! $organization, 403, 'Aucune société active n’est associée à votre compte.');

        return $organization;
    }

    private function authorizeManagement(Request $request, Organization $organization): void
    {
        $isOwner = $request->user()->organizations()
            ->whereKey($organization->id)
            ->wherePivot('is_owner', true)
            ->exists();
        $hasAdministrativeRole = $request->user()->roles()
            ->wherePivot('organization_id', $organization->id)
            ->whereIn('roles.slug', ['administrator', 'director'])
            ->exists();

        abort_unless($isOwner || $hasAdministrativeRole, 403, 'Vous ne pouvez pas gérer les catégories de projet.');
    }

    private function findScopedCategory(Request $request, int $categoryId): ProjectCategory
    {
        return ProjectCategory::query()
            ->whereKey($categoryId)
            ->whereBelongsTo($this->getCurrentOrganization($request))
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, Organization $organization, ?ProjectCategory $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'manager_id' => ['required', 'integer', 'exists:users,id'],
            'description' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $managerExists = User::query()
            ->whereKey($data['manager_id'])
            ->whereHas('organizations', fn ($query) => $query
                ->whereKey($organization->id)
                ->where('organization_members.is_active', true))
            ->exists();

        if (! $managerExists) {
            throw ValidationException::withMessages([
                'manager_id' => 'Le chef de projet doit être un membre actif de la société actuelle.',
            ]);
        }

        $baseSlug = Str::slug($data['name']) ?: 'categorie';
        $slug = $baseSlug;
        $suffix = 2;

        while (ProjectCategory::query()
            ->whereBelongsTo($organization)
            ->where('slug', $slug)
            ->when($category, fn ($query) => $query->whereKeyNot($category->id))
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return [...$data, 'slug' => $slug];
    }

    public function index(Request $request): JsonResponse
    {
        $organization = $this->getCurrentOrganization($request);

        return response()->json([
            'project_categories' => ProjectCategory::query()
                ->whereBelongsTo($organization)
                ->with('manager:id,name,email')
                ->withCount('projects')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->getCurrentOrganization($request);
        $this->authorizeManagement($request, $organization);

        ProjectCategory::query()->create([
            ...$this->validateData($request, $organization),
            'organization_id' => $organization->id,
        ]);

        return back()->with('success', 'Catégorie de projet créée avec succès.');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'project_category' => $this->findScopedCategory($request, (int) $id)
                ->load('manager:id,name,email')
                ->loadCount('projects'),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $organization = $this->getCurrentOrganization($request);
        $this->authorizeManagement($request, $organization);
        $category = $this->findScopedCategory($request, (int) $id);
        $category->update($this->validateData($request, $organization, $category));

        return back()->with('success', 'Catégorie de projet mise à jour avec succès.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $organization = $this->getCurrentOrganization($request);
        $this->authorizeManagement($request, $organization);
        $category = $this->findScopedCategory($request, (int) $id);

        if ($category->projects()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Cette catégorie contient encore des projets. Réaffectez-les avant de la supprimer.',
            ]);
        }

        $category->delete();

        return back()->with('success', 'Catégorie de projet supprimée avec succès.');
    }
}
