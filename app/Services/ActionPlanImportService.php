<?php

namespace App\Services;

use App\Models\Action;
use App\Models\ActionPlan;
use App\Models\Axis;
use App\Models\Department;
use App\Models\Organization;
use App\Models\PlanningYear;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class ActionPlanImportService
{
    private const MAX_ARCHIVE_ENTRIES = 200;

    private const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private readonly UniqueCodeGenerator $codeGenerator,
    ) {}

    /** @return array{plans: int, axes: int, projects: int, actions: int} */
    public function import(
        UploadedFile $file,
        Organization $organization,
        Department $department,
        PlanningYear $planningYear,
        User $user,
    ): array {
        $sheets = $this->readCompatibleSheets($file->getRealPath());

        if ($sheets === []) {
            throw ValidationException::withMessages([
                'file' => 'Aucune feuille compatible trouvée. La feuille doit contenir les colonnes Action, Responsable, Statut et % avancement.',
            ]);
        }

        return DB::transaction(function () use ($sheets, $organization, $department, $planningYear, $user): array {
            $totals = ['plans' => 0, 'axes' => 0, 'projects' => 0, 'actions' => 0];

            foreach ($sheets as $sheet) {
                $result = $this->persistSheet($sheet, $organization, $department, $planningYear, $user);

                foreach (array_keys($totals) as $key) {
                    $totals[$key] += $result[$key];
                }
            }

            return $totals;
        });
    }

    /**
     * @param  array{name: string, department: ?string, projects: array<int, array<string, mixed>>}  $sheet
     * @return array{plans: int, axes: int, projects: int, actions: int}
     */
    private function persistSheet(
        array $sheet,
        Organization $organization,
        Department $department,
        PlanningYear $planningYear,
        User $user,
    ): array {
        $isInfrastructure = Str::of($sheet['name'].' '.($sheet['department'] ?? ''))
            ->ascii()->lower()->contains('infra');
        $portfolio = $isInfrastructure ? 'INFRA' : 'SOLUTIONS';
        $planName = "PAC {$portfolio} · ".trim($sheet['name']);
        $planProgress = (int) round(collect($sheet['projects'])->avg('progress') ?? 0);

        $actionPlan = ActionPlan::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'planning_year_id' => $planningYear->id,
            'created_by' => $user->id,
            'name' => $planName,
            'code' => $this->codeGenerator->forActionPlan($planName, $organization->id),
            'description' => 'Importé depuis la feuille Excel « '.$sheet['name'].' »'.($sheet['department'] ? ' · '.$sheet['department'] : ''),
            'start_date' => $planningYear->start_date,
            'end_date' => $planningYear->end_date,
            'status' => 'active',
            'progress' => $planProgress,
            'is_active' => true,
        ]);

        $category = ProjectCategory::query()
            ->where('organization_id', $organization->id)
            ->when(
                $isInfrastructure,
                fn ($query) => $query->where('slug', 'infrastructure'),
                fn ($query) => $query->where('slug', '!=', 'infrastructure'),
            )
            ->orderByRaw("CASE WHEN slug = 'autre' THEN 0 ELSE 1 END")
            ->first();
        $axes = [];
        $axisCount = 0;
        $projectCount = 0;
        $actionCount = 0;

        foreach ($sheet['projects'] as $projectPosition => $projectData) {
            $axisName = $projectData['axis'] ?: 'Axe importé';

            if (! isset($axes[$axisName])) {
                $axes[$axisName] = Axis::query()->create([
                    'action_plan_id' => $actionPlan->id,
                    'name' => $axisName,
                    'code' => Str::of($axisName)->ascii()->slug('-')->upper()->limit(255, '')->toString() ?: null,
                    'position' => $axisCount,
                    'is_active' => true,
                ]);
                $axisCount++;
            }

            $project = Project::query()->create([
                'organization_id' => $organization->id,
                'department_id' => $department->id,
                'action_plan_id' => $actionPlan->id,
                'axis_id' => $axes[$axisName]->id,
                'project_category_id' => $category?->id,
                'created_by' => $user->id,
                'name' => $projectData['name'],
                'code' => $this->codeGenerator->forProject($projectData['name'], $organization->id),
                'description' => 'Projet importé depuis Excel.',
                'start_date' => $projectData['start_date'],
                'due_date' => $projectData['due_date'],
                'status' => $this->projectStatus((int) $projectData['progress']),
                'priority' => $projectData['priority'],
                'position' => $projectPosition + 1,
                'progress' => $projectData['progress'],
                'is_private' => false,
                'is_active' => true,
            ]);
            $project->departments()->sync([$department->id]);

            foreach ($projectData['actions'] as $actionPosition => $actionData) {
                Action::query()->create([
                    'organization_id' => $organization->id,
                    'project_id' => $project->id,
                    'action_plan_id' => $actionPlan->id,
                    'axis_id' => $axes[$axisName]->id,
                    'created_by' => $user->id,
                    'title' => Str::limit($actionData['title'], 255, ''),
                    'description' => $this->actionDescription($actionData),
                    'start_date' => $actionData['start_date'],
                    'due_date' => $actionData['due_date'],
                    'actual_start_date' => $actionData['actual_start_date'],
                    'actual_end_date' => $actionData['actual_end_date'],
                    'status' => $actionData['status'],
                    'priority' => $actionData['priority'],
                    'progress' => $actionData['progress'],
                    'position' => $actionPosition + 1,
                ]);
                $actionCount++;
            }

            $projectCount++;
        }

        return ['plans' => 1, 'axes' => $axisCount, 'projects' => $projectCount, 'actions' => $actionCount];
    }

    /** @param array<string, mixed> $action */
    private function actionDescription(array $action): ?string
    {
        $lines = [];

        if ($action['responsible']) {
            $lines[] = 'Responsable source : '.$action['responsible'];
        }

        if ($action['comments']) {
            $lines[] = 'Commentaires source : '.$action['comments'];
        }

        return $lines === [] ? null : implode("\n\n", $lines);
    }

    private function projectStatus(int $progress): string
    {
        if ($progress >= 100) {
            return 'completed';
        }

        return $progress > 0 ? 'in_progress' : 'planned';
    }

    /** @return array<int, array{name: string, department: ?string, projects: array<int, array<string, mixed>>}> */
    private function readCompatibleSheets(string $path): array
    {
        $archive = new ZipArchive;

        if ($archive->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Le fichier Excel est illisible ou endommagé.']);
        }

        try {
            $this->validateArchive($archive);
            $sharedStrings = $this->readSharedStrings($archive);
            $sheets = [];

            foreach ($this->sheetDefinitions($archive) as $definition) {
                if ($definition['state'] !== 'visible') {
                    continue;
                }

                $rows = $this->readRows($archive, $definition['path'], $sharedStrings);
                $parsed = $this->parseSheet($definition['name'], $rows);

                if ($parsed !== null) {
                    $sheets[] = $parsed;
                }
            }

            return $sheets;
        } finally {
            $archive->close();
        }
    }

    private function validateArchive(ZipArchive $archive): void
    {
        if ($archive->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw ValidationException::withMessages(['file' => 'Le fichier Excel contient trop d’éléments.']);
        }

        $uncompressedBytes = 0;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $statistics = $archive->statIndex($index);
            $uncompressedBytes += (int) ($statistics['size'] ?? 0);

            if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw ValidationException::withMessages(['file' => 'Le contenu décompressé du fichier Excel est trop volumineux.']);
            }
        }
    }

    /** @return array<int, string> */
    private function readSharedStrings(ZipArchive $archive): array
    {
        $xml = $archive->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $document = $this->loadXml($xml);
        $xpath = new DOMXPath($document);
        $strings = [];

        foreach ($xpath->query('//*[local-name()="si"]') ?: [] as $item) {
            $value = '';

            foreach ($xpath->query('.//*[local-name()="t"]', $item) ?: [] as $text) {
                $value .= $text->textContent;
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /** @return array<int, array{name: string, state: string, path: string}> */
    private function sheetDefinitions(ZipArchive $archive): array
    {
        $workbookXml = $archive->getFromName('xl/workbook.xml');
        $relationshipsXml = $archive->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relationshipsXml === false) {
            throw ValidationException::withMessages(['file' => 'La structure du classeur Excel est incomplète.']);
        }

        $relationshipsDocument = $this->loadXml($relationshipsXml);
        $relationshipsXpath = new DOMXPath($relationshipsDocument);
        $targets = [];

        foreach ($relationshipsXpath->query('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            if ($relationship instanceof DOMElement) {
                $targets[$relationship->getAttribute('Id')] = $relationship->getAttribute('Target');
            }
        }

        $workbookDocument = $this->loadXml($workbookXml);
        $workbookXpath = new DOMXPath($workbookDocument);
        $definitions = [];

        foreach ($workbookXpath->query('//*[local-name()="sheet"]') ?: [] as $sheet) {
            if (! $sheet instanceof DOMElement) {
                continue;
            }

            $relationshipId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $target = $targets[$relationshipId] ?? null;

            if (! $target || str_contains($target, '..')) {
                continue;
            }

            $definitions[] = [
                'name' => $sheet->getAttribute('name'),
                'state' => $sheet->getAttribute('state') ?: 'visible',
                'path' => 'xl/'.ltrim($target, '/'),
            ];
        }

        return $definitions;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<int, string|float|int|null>>
     */
    private function readRows(ZipArchive $archive, string $path, array $sharedStrings): array
    {
        $xml = $archive->getFromName($path);

        if ($xml === false) {
            return [];
        }

        $document = $this->loadXml($xml);
        $xpath = new DOMXPath($document);
        $rows = [];

        foreach ($xpath->query('//*[local-name()="row"]') ?: [] as $row) {
            $rowNumber = $row instanceof DOMElement ? (int) $row->getAttribute('r') : 0;

            foreach ($xpath->query('./*[local-name()="c"]', $row) ?: [] as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }

                preg_match('/^[A-Z]+/', $cell->getAttribute('r'), $matches);
                $columnIndex = $this->columnIndex($matches[0] ?? 'A');
                $valueNode = $xpath->query('./*[local-name()="v"]', $cell)?->item(0);
                $inlineNode = $xpath->query('./*[local-name()="is"]//*[local-name()="t"]', $cell)?->item(0);
                $rawValue = $valueNode?->textContent ?? $inlineNode?->textContent;

                if ($rawValue === null || $rawValue === '') {
                    continue;
                }

                $rows[$rowNumber][$columnIndex] = $cell->getAttribute('t') === 's'
                    ? ($sharedStrings[(int) $rawValue] ?? '')
                    : (is_numeric($rawValue) ? (float) $rawValue : $rawValue);
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, string|float|int|null>>  $rows
     * @return array{name: string, department: ?string, projects: array<int, array<string, mixed>>}|null
     */
    private function parseSheet(string $sheetName, array $rows): ?array
    {
        $header = $this->findHeader($rows);

        if ($header === null) {
            return null;
        }

        $department = null;
        $projects = [];
        $currentProject = null;
        $currentAxis = null;

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber <= $header['row']) {
                $department ??= $this->departmentFromRow($row);

                continue;
            }

            $actionTitle = $this->stringValue($row[$header['action']] ?? null);

            if ($actionTitle === '' || Str::of($actionTitle)->ascii()->lower()->startsWith('statut et avancement global')) {
                continue;
            }

            $rubrique = $this->rubriqueFromRow($row, $header['action']);

            if ($rubrique !== null) {
                $currentProject = count($projects);
                $currentAxis = $this->siteFromRow($row, $header['action']) ?: $currentAxis ?: 'Axe importé';
                $projects[] = ['name' => $this->cleanProjectName($rubrique), 'axis' => $currentAxis, 'actions' => []];
            }

            if ($currentProject === null) {
                continue;
            }

            $sourceStatus = $this->stringValue($row[$header['status']] ?? null);
            $projects[$currentProject]['actions'][] = [
                'title' => $actionTitle,
                'responsible' => $this->stringValue($row[$header['responsible']] ?? null),
                'start_date' => $this->dateValue($row[$header['planned_start']] ?? null, false),
                'due_date' => $this->dateValue($row[$header['planned_end']] ?? null, true),
                'actual_start_date' => $this->dateValue($row[$header['actual_start']] ?? null, false),
                'actual_end_date' => $this->dateValue($row[$header['actual_end']] ?? null, true),
                'status' => $this->actionStatus($sourceStatus),
                'priority' => $this->priority($sourceStatus),
                'progress' => $this->progressValue($row[$header['progress']] ?? null),
                'comments' => $this->stringValue($row[$header['comments']] ?? null),
            ];
        }

        $projects = collect($projects)
            ->filter(fn (array $project): bool => $project['actions'] !== [])
            ->map(function (array $project): array {
                $actions = collect($project['actions']);
                $project['progress'] = (int) round($actions->avg('progress') ?? 0);
                $project['start_date'] = $actions->pluck('start_date')->filter()->sort()->first();
                $project['due_date'] = $actions->pluck('due_date')->filter()->sortDesc()->first();
                $project['priority'] = $actions->contains('priority', 'critical') ? 'critical' : ($actions->contains('priority', 'high') ? 'high' : 'medium');

                return $project;
            })
            ->values()
            ->all();

        return $projects === [] ? null : ['name' => $sheetName, 'department' => $department, 'projects' => $projects];
    }

    /**
     * @param  array<int, array<int, string|float|int|null>>  $rows
     * @return array<string, int>|null
     */
    private function findHeader(array $rows): ?array
    {
        foreach ($rows as $rowNumber => $row) {
            $normalized = collect($row)->map(fn ($value): string => $this->normalize($this->stringValue($value)));
            $columns = [
                'row' => $rowNumber,
                'action' => $normalized->search(fn (string $value): bool => $value === 'action'),
                'responsible' => $normalized->search(fn (string $value): bool => str_contains($value, 'responsable')),
                'planned_start' => $normalized->search(fn (string $value): bool => str_contains($value, 'demarrage prevu')),
                'planned_end' => $normalized->search(fn (string $value): bool => str_contains($value, 'finalisation prevu')),
                'actual_start' => $normalized->search(fn (string $value): bool => str_contains($value, 'demarrage effect') || str_contains($value, 'demarrage reel')),
                'actual_end' => $normalized->search(fn (string $value): bool => str_contains($value, 'finalisation effect')),
                'status' => $normalized->search(fn (string $value): bool => $value === 'statut'),
                'progress' => $normalized->search(fn (string $value): bool => str_contains($value, 'avancement')),
                'comments' => $normalized->search(fn (string $value): bool => str_contains($value, 'commentaire') || str_contains($value, 'recommandation')),
            ];

            if ($columns['action'] !== false && $columns['responsible'] !== false && $columns['status'] !== false && $columns['progress'] !== false) {
                return collect($columns)->map(fn ($value): int => $value === false ? -1 : (int) $value)->all();
            }
        }

        return null;
    }

    /** @param array<int, string|float|int|null> $row */
    private function departmentFromRow(array $row): ?string
    {
        foreach ($row as $value) {
            $text = $this->stringValue($value);

            if (Str::of($text)->ascii()->lower()->contains('dsi')) {
                return $text;
            }
        }

        return null;
    }

    /** @param array<int, string|float|int|null> $row */
    private function rubriqueFromRow(array $row, int $actionColumn): ?string
    {
        foreach ($row as $column => $value) {
            $text = $this->stringValue($value);

            if ($column < $actionColumn && Str::of($text)->ascii()->lower()->contains('rubrique')) {
                return $text;
            }
        }

        return null;
    }

    /** @param array<int, string|float|int|null> $row */
    private function siteFromRow(array $row, int $actionColumn): ?string
    {
        foreach ($row as $column => $value) {
            $text = $this->stringValue($value);

            if ($column < $actionColumn && $text !== '' && ! Str::of($text)->ascii()->lower()->contains('rubrique')) {
                return $text;
            }
        }

        return null;
    }

    private function cleanProjectName(string $value): string
    {
        $name = preg_replace('/^\s*Rubrique\s*\d*\s*[:\-]?\s*/iu', '', $value) ?: $value;

        return Str::limit(trim($name), 255, '');
    }

    private function actionStatus(string $status): string
    {
        $normalized = $this->normalize($status);

        return match (true) {
            str_contains($normalized, 'final') => 'completed',
            str_contains($normalized, 'annul') => 'cancelled',
            str_contains($normalized, 'attente'), str_contains($normalized, 'retard') => 'on_hold',
            str_contains($normalized, 'cours') => 'in_progress',
            default => 'not_started',
        };
    }

    private function priority(string $status): string
    {
        $normalized = $this->normalize($status);

        return match (true) {
            str_contains($normalized, 'retard eleve') => 'critical',
            str_contains($normalized, 'retard') => 'high',
            default => 'medium',
        };
    }

    private function progressValue(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $number = (float) $value;

        return (int) max(0, min(100, round($number <= 1 ? $number * 100 : $number)));
    }

    private function dateValue(mixed $value, bool $endOfMonth): ?string
    {
        if (is_numeric($value)) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $value))->toDateString();
        }

        $normalized = $this->normalize($this->stringValue($value));

        if (! preg_match('/^(jan|fev|mar|avr|mai|jui|juil|aou|sep|oct|nov|dec)[a-z]*[-\s\/](\d{2}|\d{4})$/', $normalized, $matches)) {
            return null;
        }

        $months = ['jan' => 1, 'fev' => 2, 'mar' => 3, 'avr' => 4, 'mai' => 5, 'jui' => 6, 'juil' => 7, 'aou' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $year = (int) $matches[2];
        $year = $year < 100 ? 2000 + $year : $year;
        $date = CarbonImmutable::create($year, $months[$matches[1]], 1);

        return ($endOfMonth ? $date->endOfMonth() : $date)->toDateString();
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function loadXml(string $xml): DOMDocument
    {
        $document = new DOMDocument;

        if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Le contenu XML du classeur est invalide.');
        }

        return $document;
    }
}
