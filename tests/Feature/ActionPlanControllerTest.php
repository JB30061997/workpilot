<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionComment;
use App\Models\ActionPlan;
use App\Models\Axis;
use App\Models\Department;
use App\Models\Organization;
use App\Models\PlanningYear;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class ActionPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_only_displays_plans_and_axes_from_selected_context(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'selected');
        [$otherOrganization, $otherDepartment] = $this->createContext($user, 'other');
        $plan = $this->createPlan($user, $organization, $department, 'Visible Plan');
        Axis::query()->create([
            'action_plan_id' => $plan->id,
            'name' => 'Visible Axis',
            'position' => 0,
            'is_active' => true,
        ]);
        $this->createPlan($user, $otherOrganization, $otherDepartment, 'Hidden Plan');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('action-plans.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('ActionPlans/Index')
            ->has('action_plans.data', 1)
            ->where('action_plans.data.0.name', 'Visible Plan')
            ->has('action_plans.data.0.axes', 1)
            ->where('action_plans.data.0.axes.0.name', 'Visible Axis'));
    }

    public function test_director_general_can_view_action_plans_from_an_unassigned_department(): void
    {
        $director = User::factory()->create();
        [$organization, $memberDepartment] = $this->createContext($director, 'director');
        $otherDepartment = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Finance',
        ]);
        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'DG — Directeur Général',
            'slug' => 'director',
            'is_active' => true,
        ]);
        $director->roles()->attach($role, ['organization_id' => $organization->id]);
        $this->createPlan($director, $organization, $memberDepartment, 'Hidden Department Plan');
        $visiblePlan = $this->createPlan($director, $organization, $otherDepartment, 'Finance Plan');

        $response = $this
            ->actingAs($director)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $otherDepartment->id,
            ])
            ->get(route('action-plans.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('department.id', $otherDepartment->id)
            ->has('action_plans.data', 1)
            ->where('action_plans.data.0.id', $visiblePlan->id));
    }

    public function test_valid_payload_creates_plan_in_selected_context(): void
    {
        $this->travelTo('2026-10-26 10:00:00');
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'create');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->from(route('action-plans.index'))
            ->post(route('action-plans.store'), [
                'name' => 'Digital Plan',
                'status' => 'active',
                'progress' => 30,
                'is_active' => true,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('action-plans.index', ['open_plan' => 1]));
        $this->assertDatabaseHas('action_plans', [
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
            'name' => 'Digital Plan',
            'code' => 'DIGITAL-PLAN',
            'status' => 'active',
            'progress' => 30,
            'start_date' => '2026-09-01 00:00:00',
            'end_date' => '2027-08-31 00:00:00',
        ]);
    }

    public function test_excel_import_detects_compatible_sheet_content_regardless_of_its_name(): void
    {
        $this->travelTo('2026-10-29 10:00:00');
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'excel-import');
        $file = $this->actionPlanWorkbook('Feuille renommée par le métier');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('action-plans.import'), ['file' => $file]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('action-plans.index'))
            ->assertSessionHas('success', 'Import terminé : 1 plan(s), 1 axe(s), 1 projet(s) et 2 action(s) créés.');
        $this->assertDatabaseHas('action_plans', [
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'name' => 'PAC SOLUTIONS · Feuille renommée par le métier',
            'progress' => 75,
        ]);
        $this->assertDatabaseHas('axes', ['name' => 'HOLGED Maroc']);
        $this->assertDatabaseHas('projects', [
            'organization_id' => $organization->id,
            'name' => 'Projet ERP groupe',
            'progress' => 75,
        ]);
        $this->assertDatabaseHas('actions', [
            'title' => 'Cadrage fonctionnel',
            'status' => 'completed',
            'progress' => 100,
        ]);
        $this->assertDatabaseHas('actions', [
            'title' => 'Déploiement pilote',
            'status' => 'in_progress',
            'progress' => 50,
        ]);
    }

    public function test_excel_import_rejects_a_workbook_without_a_compatible_sheet(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'invalid-excel-import');
        $file = $this->actionPlanWorkbook('Tableau libre', false);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->from(route('action-plans.index'))
            ->post(route('action-plans.import'), ['file' => $file]);

        $response
            ->assertRedirect(route('action-plans.index'))
            ->assertSessionHasErrors([
                'file' => 'Aucune feuille compatible trouvée. La feuille doit contenir les colonnes Action, Responsable, Statut et % avancement.',
            ]);
        $this->assertDatabaseCount('action_plans', 0);
    }

    public function test_plan_cannot_use_a_planning_year_from_another_organization(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'selected-year');
        [$otherOrganization] = $this->createContext($user, 'other-year');
        $otherYear = PlanningYear::query()->create([
            'organization_id' => $otherOrganization->id,
            'label' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
        ]);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('action-plans.store'), [
                'name' => 'Cross tenant plan',
                'planning_year_id' => $otherYear->id,
                'status' => 'active',
                'progress' => 0,
                'is_active' => true,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('action_plans', [
            'organization_id' => $organization->id,
            'name' => 'Cross tenant plan',
        ]);
    }

    public function test_same_plan_name_gets_an_organization_unique_numeric_code(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'duplicate-code');
        $session = [
            'current_organization_id' => $organization->id,
            'current_department_id' => $department->id,
        ];
        $payload = [
            'name' => 'Gestion des immobilisations',
            'status' => 'active',
            'progress' => 0,
            'is_active' => true,
        ];

        $this->actingAs($user)->withSession($session)->post(route('action-plans.store'), $payload);
        $this->actingAs($user)->withSession($session)->post(route('action-plans.store'), $payload);

        $this->assertDatabaseHas('action_plans', ['code' => 'GESTION-DES-IMMOBILISATIONS']);
        $this->assertDatabaseHas('action_plans', ['code' => 'GESTION-DES-IMMOBILISATIONS-2']);
    }

    public function test_updating_plan_name_preserves_generated_code(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'stable-code');
        $plan = $this->createPlan($user, $organization, $department, 'Initial Name');
        $plan->update(['code' => 'INITIAL-NAME']);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('action-plans.update', $plan), [
                'name' => 'Renamed Plan',
                'code' => 'MALICIOUS-CHANGE',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('action_plans', [
            'id' => $plan->id,
            'name' => 'Renamed Plan',
            'code' => 'INITIAL-NAME',
        ]);
    }

    public function test_plan_consultation_data_contains_projects_and_ordered_actions(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'consultation');
        $plan = $this->createPlan($user, $organization, $department, 'Consultation Plan');
        $project = Project::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'action_plan_id' => $plan->id,
            'created_by' => $user->id,
            'name' => 'Consultation Project',
            'status' => 'in_progress',
            'priority' => 'high',
            'progress' => 50,
        ]);
        Action::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'action_plan_id' => $plan->id,
            'created_by' => $user->id,
            'title' => 'Second step',
            'status' => 'in_progress',
            'priority' => 'high',
            'progress' => 25,
            'position' => 2,
        ]);
        $firstAction = Action::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'action_plan_id' => $plan->id,
            'created_by' => $user->id,
            'title' => 'First step',
            'status' => 'completed',
            'priority' => 'high',
            'progress' => 100,
            'position' => 1,
        ]);
        ActionComment::query()->create([
            'action_id' => $firstAction->id,
            'user_id' => $user->id,
            'content' => 'Ready for validation.',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('action-plans.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('action_plans.data.0.projects', 1)
            ->where('action_plans.data.0.projects.0.id', $project->id)
            ->where('action_plans.data.0.projects.0.actions_count', 2)
            ->has('action_plans.data.0.projects.0.actions', 2)
            ->where('action_plans.data.0.projects.0.actions.0.id', $firstAction->id)
            ->has('action_plans.data.0.projects.0.actions.0.comments', 1)
            ->where('action_plans.data.0.projects.0.actions.0.comments.0.content', 'Ready for validation.')
            ->where('action_plans.data.0.projects.0.actions.0.comments.0.user.name', $user->name));
    }

    public function test_axis_can_be_created_for_plan_in_selected_context(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'axis');
        $plan = $this->createPlan($user, $organization, $department, 'Axis Plan');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('axes.store'), [
                'action_plan_id' => $plan->id,
                'name' => 'Infrastructure',
                'position' => 1,
                'is_active' => true,
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('axes', [
            'action_plan_id' => $plan->id,
            'name' => 'Infrastructure',
            'position' => 1,
        ]);
    }

    public function test_axis_cannot_be_created_for_plan_from_another_context(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'current');
        [$otherOrganization, $otherDepartment] = $this->createContext($user, 'restricted');
        $otherPlan = $this->createPlan($user, $otherOrganization, $otherDepartment, 'Restricted Plan');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('axes.store'), [
                'action_plan_id' => $otherPlan->id,
                'name' => 'Restricted Axis',
                'position' => 1,
                'is_active' => true,
            ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('axes', ['name' => 'Restricted Axis']);
    }

    /**
     * @return array{Organization, Department}
     */
    private function createContext(User $user, string $key): array
    {
        $organization = Organization::query()->create([
            'name' => ucfirst($key).' Company',
            'slug' => $key.'-company',
        ]);
        $department = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => ucfirst($key).' Department',
        ]);
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_active' => true]);

        return [$organization, $department];
    }

    private function createPlan(
        User $user,
        Organization $organization,
        Department $department,
        string $name,
    ): ActionPlan {
        return ActionPlan::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
            'name' => $name,
            'status' => 'active',
            'progress' => 0,
            'is_active' => true,
        ]);
    }

    private function actionPlanWorkbook(string $sheetName, bool $compatible = true): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'workpilot-action-plan-');
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $archive->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.htmlspecialchars($sheetName, ENT_XML1).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $sheetRows = $compatible
            ? '<row r="3"><c r="D3" t="inlineStr"><is><t>DSI - Projets groupe</t></is></c></row>
                <row r="5"><c r="A5" t="inlineStr"><is><t>Site</t></is></c><c r="D5" t="inlineStr"><is><t>Action</t></is></c><c r="G5" t="inlineStr"><is><t>Responsable</t></is></c><c r="I5" t="inlineStr"><is><t>Date de démarrage prévue</t></is></c><c r="K5" t="inlineStr"><is><t>Date de finalisation prévue</t></is></c><c r="M5" t="inlineStr"><is><t>Date de démarrage effective</t></is></c><c r="O5" t="inlineStr"><is><t>Statut</t></is></c><c r="Q5" t="inlineStr"><is><t>% avancement</t></is></c><c r="S5" t="inlineStr"><is><t>Date de finalisation effective</t></is></c><c r="U5" t="inlineStr"><is><t>Commentaires</t></is></c></row>
                <row r="8"><c r="A8" t="inlineStr"><is><t>HOLGED Maroc</t></is></c><c r="B8" t="inlineStr"><is><t>Rubrique 01 : Projet ERP groupe</t></is></c><c r="D8" t="inlineStr"><is><t>Cadrage fonctionnel</t></is></c><c r="G8" t="inlineStr"><is><t>DSI / Métier</t></is></c><c r="I8"><v>46300</v></c><c r="K8"><v>46310</v></c><c r="O8" t="inlineStr"><is><t>Finalisé</t></is></c><c r="Q8"><v>1</v></c><c r="U8" t="inlineStr"><is><t>Validé</t></is></c></row>
                <row r="9"><c r="D9" t="inlineStr"><is><t>Déploiement pilote</t></is></c><c r="G9" t="inlineStr"><is><t>DSI</t></is></c><c r="I9"><v>46311</v></c><c r="K9"><v>46320</v></c><c r="O9" t="inlineStr"><is><t>En cours</t></is></c><c r="Q9"><v>0.5</v></c></row>'
            : '<row r="1"><c r="A1" t="inlineStr"><is><t>Simple tableau</t></is></c></row>';
        $archive->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $archive->close();

        return new UploadedFile(
            $path,
            'plan-action.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            UPLOAD_ERR_OK,
            true,
        );
    }
}
