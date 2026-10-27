<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DefaultRoleProvisioner
{
    /** @return Collection<int, Role> */
    public function provision(Organization $organization, bool $syncExistingPermissions = false): Collection
    {
        $permissionsBySlug = Permission::query()->where('is_active', true)->pluck('id', 'slug');

        return DB::transaction(function () use ($organization, $permissionsBySlug, $syncExistingPermissions): Collection {
            return collect($this->definitions())->map(function (array $definition) use ($organization, $permissionsBySlug, $syncExistingPermissions): Role {
                $role = Role::query()->firstOrNew([
                    'organization_id' => $organization->id,
                    'slug' => $definition['slug'],
                ]);
                $isNewRole = ! $role->exists;

                $role->fill([
                    'name' => $definition['name'],
                    ...($isNewRole ? ['description' => $definition['description']] : []),
                    'is_system' => true,
                    'is_active' => true,
                ])->save();

                if ($isNewRole || $syncExistingPermissions) {
                    $permissionIds = $definition['permissions'] === ['*']
                        ? $permissionsBySlug->values()
                        : $permissionsBySlug->only($definition['permissions'])->values();
                    $role->permissions()->sync($permissionIds);
                }

                return $role;
            });
        });
    }

    /**
     * @return array<int, array{name: string, slug: string, description: string, permissions: array<int, string>}>
     */
    private function definitions(): array
    {
        $operationalManagement = [
            'departments.view', 'users.view',
            'action_plans.view', 'action_plans.create', 'action_plans.update', 'action_plans.delete',
            'projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.manage_members',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete', 'tasks.assign', 'tasks.change_status',
            'sessions.view', 'sessions.create', 'sessions.update', 'sessions.delete', 'sessions.manage_members',
            'planning.view', 'planning.create', 'planning.update', 'planning.delete',
            'reports.view', 'activities.view',
        ];

        return [
            ['name' => 'Administrateur', 'slug' => 'administrator', 'description' => 'Administration technique complète de WorkPilot.', 'permissions' => ['*']],
            ['name' => 'DG — Directeur Général', 'slug' => 'director', 'description' => 'Pilotage global, gouvernance et accès complet aux données de la société.', 'permissions' => ['*']],
            [
                'name' => 'DGA — Directeur Général Adjoint', 'slug' => 'deputy-director',
                'description' => 'Pilotage transversal et suppléance de la direction générale.',
                'permissions' => [...$operationalManagement, 'organizations.view', 'departments.create', 'departments.update', 'users.create', 'users.update', 'users.assign_role', 'roles.view', 'roles.create', 'roles.update', 'permissions.view'],
            ],
            [
                'name' => 'Directeur de département', 'slug' => 'department-manager',
                'description' => 'Gestion des équipes, plans, projets et activités de son département.',
                'permissions' => [...$operationalManagement, 'departments.update', 'users.create', 'users.update', 'users.assign_role', 'roles.view', 'permissions.view'],
            ],
            [
                'name' => 'Chef de projet', 'slug' => 'project-manager',
                'description' => 'Conduite des projets, organisation des tâches et coordination des équipes.',
                'permissions' => ['projects.view', 'projects.create', 'projects.update', 'projects.manage_members', 'tasks.view', 'tasks.create', 'tasks.update', 'tasks.assign', 'tasks.change_status', 'sessions.view', 'sessions.create', 'sessions.update', 'sessions.manage_members', 'planning.view', 'planning.create', 'planning.update'],
            ],
            [
                'name' => 'Collaborateur', 'slug' => 'collaborator',
                'description' => 'Exécution des tâches, consultation des projets et gestion de son planning.',
                'permissions' => ['projects.view', 'tasks.view', 'tasks.create', 'tasks.update', 'tasks.change_status', 'sessions.view', 'planning.view', 'planning.create', 'planning.update'],
            ],
        ];
    }
}
