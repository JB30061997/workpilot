<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            'organizations' => [
                'view',
                'update',
            ],

            'departments' => [
                'view',
                'create',
                'update',
                'delete',
            ],

            'users' => [
                'view',
                'create',
                'update',
                'delete',
                'assign_role',
            ],

            'roles' => [
                'view',
                'create',
                'update',
                'delete',
            ],

            'action_plans' => [
                'view',
                'create',
                'update',
                'delete',
            ],

            'projects' => [
                'view',
                'create',
                'update',
                'delete',
                'manage_members',
            ],

            'tasks' => [
                'view',
                'create',
                'update',
                'delete',
                'assign',
                'change_status',
            ],

            'sessions' => [
                'view',
                'create',
                'update',
                'delete',
                'manage_members',
            ],

            'planning' => [
                'view',
                'create',
                'update',
                'delete',
            ],

            'reports' => [
                'view',
            ],

            'activities' => [
                'view',
            ],
        ];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                $slug = "{$module}.{$action}";

                Permission::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => ucwords(
                            str_replace('_', ' ', $module)
                        ).' - '.ucwords(
                            str_replace('_', ' ', $action)
                        ),

                        'module' => $module,
                        'description' => null,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}