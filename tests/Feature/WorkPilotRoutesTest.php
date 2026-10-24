<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkPilotRoutesTest extends TestCase
{
    #[DataProvider('resourceRouteProvider')]
    public function test_workpilot_resource_routes_are_registered(
        string $routeName,
        string $httpMethod,
    ): void {
        $route = app('router')->getRoutes()->getByName($routeName);

        $this->assertNotNull($route);
        $this->assertContains($httpMethod, $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('verified', $route->gatherMiddleware());
    }

    public function test_unauthenticated_request_to_workpilot_resource_redirects_to_login(): void
    {
        $response = $this->get(route('projects.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_every_workpilot_route_points_to_an_existing_controller_method(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (! in_array('verified', $route->gatherMiddleware(), true)) {
                continue;
            }

            $action = $route->getActionName();
            [$controller, $method] = str_contains($action, '@')
                ? explode('@', $action)
                : [$action, '__invoke'];

            $this->assertTrue(
                method_exists($controller, $method),
                "Route [{$route->getName()}] references missing method [{$route->getActionName()}].",
            );
        }
    }

    public static function resourceRouteProvider(): array
    {
        $resources = [
            'action-plans',
            'actions',
            'activities',
            'attachments',
            'axes',
            'checklist-items',
            'comments',
            'department-members',
            'departments',
            'organization-members',
            'organizations',
            'permissions',
            'project-members',
            'projects',
            'reports',
            'roles',
            'session-members',
            'task-assignees',
            'task-statuses',
            'tasks',
            'time-slot-members',
            'time-slots',
            'users',
            'work-sessions',
        ];

        $routes = [];

        foreach ($resources as $resource) {
            $routes[$resource.' index'] = [$resource.'.index', 'GET'];

            if (in_array($resource, ['activities', 'reports'], true)) {
                continue;
            }

            $routes[$resource.' store'] = [$resource.'.store', 'POST'];
        }

        $routes['attachment download'] = ['attachments.download', 'GET'];
        $routes['checklist item complete'] = ['checklist-items.complete', 'PATCH'];
        $routes['checklist item reopen'] = ['checklist-items.reopen', 'PATCH'];

        return $routes;
    }
}
