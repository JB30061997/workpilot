
<?php

use App\Http\Controllers\AccessControlController;
use App\Http\Controllers\ActionCommentController;
use App\Http\Controllers\ActionController;
use App\Http\Controllers\ActionPlanController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AxisController;
use App\Http\Controllers\ChecklistItemController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CurrentContextController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DepartmentMemberController;
use App\Http\Controllers\GlpiController;
use App\Http\Controllers\GmailIntegrationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMemberController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectCategoryController;
use App\Http\Controllers\ProjectChatController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\ReferentialController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SessionMemberController;
use App\Http\Controllers\TaskAssigneeController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskPlanningController;
use App\Http\Controllers\TaskStatusController;
use App\Http\Controllers\TimeSlotController;
use App\Http\Controllers\TimeSlotMemberController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkflowAIController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\WorkSessionController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Welcome
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('/workflows', [WorkflowController::class, 'library'])
        ->name('workflows.library');

    /*
    |--------------------------------------------------------------------------
    | GLPI & Gmail Integration
    |--------------------------------------------------------------------------
    */

    Route::get('/glpi', [GlpiController::class, 'index'])
        ->name('glpi.index');

    Route::put('/glpi/configuration', [GlpiController::class, 'update'])
        ->name('glpi.update');

    Route::post('/glpi/test-connection', [GlpiController::class, 'testConnection'])
        ->name('glpi.test');

    Route::get('/glpi/google/connect', [GmailIntegrationController::class, 'connect'])
        ->name('glpi.gmail.connect');

    Route::get('/glpi/google/callback', [GmailIntegrationController::class, 'callback'])
        ->name('glpi.gmail.callback');

    Route::post('/glpi/google/sync', [GmailIntegrationController::class, 'sync'])
        ->name('glpi.gmail.sync');

    Route::delete('/glpi/google/disconnect', [GmailIntegrationController::class, 'disconnect'])
        ->name('glpi.gmail.disconnect');

    /*
    |--------------------------------------------------------------------------
    | Planning
    |--------------------------------------------------------------------------
    */

    Route::get('/planning', [TaskPlanningController::class, 'index'])
        ->name('planning.index');

    Route::post('/planning', [TaskPlanningController::class, 'store'])
        ->name('planning.store');

    Route::put('/planning/{taskPlanning}', [TaskPlanningController::class, 'update'])
        ->name('planning.update');

    Route::delete('/planning/{taskPlanning}', [TaskPlanningController::class, 'destroy'])
        ->name('planning.destroy');

    Route::post('/planning/{taskPlanning}/duplicate', [TaskPlanningController::class, 'duplicate'])
        ->name('planning.duplicate');

    /*
    |--------------------------------------------------------------------------
    | Current Context
    |--------------------------------------------------------------------------
    */

    Route::patch('/context', CurrentContextController::class)
        ->name('context.update');

    /*
    |--------------------------------------------------------------------------
    | Referential
    |--------------------------------------------------------------------------
    */

    Route::get('/referential', [ReferentialController::class, 'index'])
        ->name('referential.index');

    Route::get('/access-control', [AccessControlController::class, 'index'])
        ->name('access-control.index');

    /*
    |--------------------------------------------------------------------------
    | Project Chat - Group Discussions
    |--------------------------------------------------------------------------
    */

    // Page principale : liste des groupes de projets
    Route::get('/project-chat', [
        ProjectChatController::class,
        'index',
    ])->name('project-chat.index');

    // Charger les messages et les membres d'un projet
    Route::get('/project-chat/projects/{project}', [
        ProjectChatController::class,
        'show',
    ])->name('project-chat.show');

    // Envoyer un message avec ou sans fichiers
    Route::post('/project-chat/projects/{project}/messages', [
        ProjectChatController::class,
        'store',
    ])->name('project-chat.store');

    Route::delete('/project-chat/projects/{project}/messages/{message}', [
        ProjectChatController::class,
        'destroy',
    ])->name('project-chat.destroy');

    // Marquer les messages comme lus
    Route::post('/project-chat/projects/{project}/read', [
        ProjectChatController::class,
        'markRead',
    ])->name('project-chat.read');

    // Télécharger une pièce jointe
    Route::get('/project-chat/attachments/{attachment}', [
        ProjectChatController::class,
        'download',
    ])->name('project-chat.download');

    /*
    |--------------------------------------------------------------------------
    | Action Comments
    |--------------------------------------------------------------------------
    */

    Route::apiResource('action-comments', ActionCommentController::class)
        ->only(['store', 'update', 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    */

    Route::get('/attachments/{attachment}/download', [
        AttachmentController::class,
        'download',
    ])->name('attachments.download');

    /*
    |--------------------------------------------------------------------------
    | Checklist Items
    |--------------------------------------------------------------------------
    */

    Route::patch('/checklist-items/{checklist_item}/complete', [
        ChecklistItemController::class,
        'complete',
    ])->name('checklist-items.complete');

    Route::patch('/checklist-items/{checklist_item}/reopen', [
        ChecklistItemController::class,
        'reopen',
    ])->name('checklist-items.reopen');

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    Route::post('/actions/{action}/duplicate', [
        ActionController::class,
        'duplicate',
    ])->name('actions.duplicate');

    /*
    |--------------------------------------------------------------------------
    | Projects
    |--------------------------------------------------------------------------
    */

    Route::post('/projects/{project}/duplicate', [
        ProjectController::class,
        'duplicate',
    ])->name('projects.duplicate');

    Route::patch('/projects/reorder', [
        ProjectController::class,
        'reorder',
    ])->name('projects.reorder');

    /*
    |--------------------------------------------------------------------------
    | Tasks
    |--------------------------------------------------------------------------
    */

    Route::patch('/tasks/{task}/status', [
        TaskController::class,
        'updateStatus',
    ])->name('tasks.status.update');

    Route::get(
        '/project-chat/groups',
        [ProjectChatController::class, 'groups']
    )->name('project-chat.groups');

    /*
    |--------------------------------------------------------------------------
    | Activities
    |--------------------------------------------------------------------------
    */

    Route::apiResource('activities', ActivityController::class)
        ->only(['index', 'show']);

    /*
    |--------------------------------------------------------------------------
    | Attachments API
    |--------------------------------------------------------------------------
    */

    Route::apiResource('attachments', AttachmentController::class)
        ->only(['index', 'store', 'show', 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    Route::apiResource('reports', ReportController::class)
        ->only(['index']);

    /*
    |--------------------------------------------------------------------------
    | API Resources
    |--------------------------------------------------------------------------
    */

    Route::apiResources([
        'action-plans' => ActionPlanController::class,
        'actions' => ActionController::class,
        'axes' => AxisController::class,
        'checklist-items' => ChecklistItemController::class,
        'comments' => CommentController::class,
        'department-members' => DepartmentMemberController::class,
        'departments' => DepartmentController::class,
        'organization-members' => OrganizationMemberController::class,
        'organizations' => OrganizationController::class,
        'permissions' => PermissionController::class,
        'project-members' => ProjectMemberController::class,
        'project-categories' => ProjectCategoryController::class,
        'projects' => ProjectController::class,
        'roles' => RoleController::class,
        'session-members' => SessionMemberController::class,
        'task-assignees' => TaskAssigneeController::class,
        'task-statuses' => TaskStatusController::class,
        'tasks' => TaskController::class,
        'time-slot-members' => TimeSlotMemberController::class,
        'time-slots' => TimeSlotController::class,
        'users' => UserController::class,
        'work-sessions' => WorkSessionController::class,
    ]);
});

/*
|--------------------------------------------------------------------------
| Profile
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');
});

Route::middleware(['auth', 'verified'])->prefix('projects/{project}/workflows')->name('workflows.')->group(function () {
    Route::get('/', [WorkflowController::class, 'index'])->name('index');
    Route::post('/', [WorkflowController::class, 'store'])->name('store');
    Route::get('/{workflow}', [WorkflowController::class, 'edit'])->name('edit');
    Route::put('/{workflow}', [WorkflowController::class, 'update'])->name('update');
    Route::post('/{workflow}/ai/generate', [WorkflowAIController::class, 'generate'])
        ->middleware('throttle:workflow-ai')
        ->name('ai.generate');
    Route::post('/{workflow}/publish', [WorkflowController::class, 'publish'])->name('publish');
    Route::post('/{workflow}/duplicate', [WorkflowController::class, 'duplicate'])->name('duplicate');
    Route::delete('/{workflow}', [WorkflowController::class, 'destroy'])->name('destroy');
});

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

require __DIR__.'/auth.php';
