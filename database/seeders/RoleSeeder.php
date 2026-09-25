<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', 'workpilot-demo')->firstOrFail();

        $roles = [
            [
                'name' => 'Administrateur',
                'slug' => 'administrator',
                'permissions' => ['*'],
            ],

            [
                'name' => 'Directeur',
                'slug' => 'director',
                'permissions' => [
                    'organizations.view',
                    'departments.view',
                    'users.view',
                    'action_plans.view',
                    'action_plans.create',
                    'action_plans.update',
                    'projects.view',
                    'projects.create',
                    'projects.update',
                    'projects.manage_members',
                    'tasks.view',
                    'tasks.create',
                    'tasks.update',
                    'tasks.assign',
                    'tasks.change_status',
                    'sessions.view',
                    'planning.view',
                    'reports.view',
                    'activities.view',
                ],
            ],

            [
                'name' => 'Manager Service',
                'slug' => 'department-manager',
                'permissions' => [
                    'departments.view',
                    'users.view',
                    'action_plans.view',
                    'projects.view',
                    'projects.create',
                    'projects.update',
                    'projects.manage_members',
                    'tasks.view',
                    'tasks.create',
                    'tasks.update',
                    'tasks.assign',
                    'tasks.change_status',
                    'sessions.view',
                    'sessions.create',
                    'sessions.update',
                    'sessions.manage_members',
                    'planning.view',
                    'planning.create',
                    'planning.update',
                    'reports.view',
                ],
            ],

            [
                'name' => 'Chef de Projet',
                'slug' => 'project-manager',
                'permissions' => [
                    'projects.view',
                    'projects.create',
                    'projects.update',
                    'projects.manage_members',
                    'tasks.view',
                    'tasks.create',
                    'tasks.update',
                    'tasks.assign',
                    'tasks.change_status',
                    'sessions.view',
                    'sessions.create',
                    'sessions.update',
                    'sessions.manage_members',
                    'planning.view',
                    'planning.create',
                    'planning.update',
                ],
            ],

            [
                'name' => 'Collaborateur',
                'slug' => 'collaborator',
                'permissions' => [
                    'projects.view',
                    'tasks.view',
                    'tasks.create',
                    'tasks.update',
                    'tasks.change_status',
                    'sessions.view',
                    'planning.view',
                    'planning.create',
                    'planning.update',
                ],
            ],

            [
                'name' => 'Lecteur',
                'slug' => 'viewer',
                'permissions' => [
                    'projects.view',
                    'tasks.view',
                    'sessions.view',
                    'planning.view',
                ],
            ],
        ];

        foreach ($roles as $roleData) {

            $role = Role::updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'slug' => $roleData['slug'],
                ],
                [
                    'name' => $roleData['name'],
                    'is_system' => true,
                    'is_active' => true,
                ]
            );

            if ($roleData['permissions'] === ['*']) {
                $role->permissions()->sync(
                    Permission::pluck('id')
                );

                continue;
            }

            $permissionIds = Permission::whereIn(
                'slug',
                $roleData['permissions']
            )->pluck('id');

            $role->permissions()->sync($permissionIds);
        }
    }
}