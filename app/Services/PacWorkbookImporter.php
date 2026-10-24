<?php

namespace App\Services;

use App\Models\Action;
use App\Models\ActionComment;
use App\Models\ActionPlan;
use App\Models\Axis;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class PacWorkbookImporter
{
    /** @return array{plans: int, axes: int, projects: int, actions: int, comments: int} */
    public function import(string $path, Organization $organization, User $creator): array
    {
        $sheets = $this->readWorkbook($path);
        $department = Department::query()
            ->whereBelongsTo($organization)
            ->where('name', 'Informatique')
            ->first();

        return DB::transaction(function () use ($sheets, $organization, $department, $creator): array {
            $counts = ['plans' => 0, 'axes' => 0, 'projects' => 0, 'actions' => 0, 'comments' => 0];

            $detailedSheets = [
                'Détail partie Infra 26-27' => ['code' => 'PAC-INFRA-26-27', 'name' => 'Détail partie Infra 26-27'],
                'Détail partie solutions 2627' => ['code' => 'PAC-SOLUTIONS-26-27', 'name' => 'Détail partie solutions 2627'],
            ];

            foreach ($detailedSheets as $sheetName => $metadata) {
                if (isset($sheets[$sheetName])) {
                    $this->importDetailedSheet($sheets[$sheetName], $metadata, $organization, $department, $creator, $counts);
                }
            }

            return $counts;
        });
    }

    /** @param array<int, array<string, string>> $rows */
    private function importDetailedSheet(array $rows, array $metadata, Organization $organization, ?Department $department, User $creator, array &$counts): void
    {
        $plan = $this->upsertPlan($metadata, $organization, $department, $creator);
        $counts['plans']++;
        $current = null;
        $site = 'Général';

        $flush = function () use (&$current, $plan, $organization, $department, $creator, &$counts): void {
            if ($current === null) {
                return;
            }

            $axis = Axis::query()->updateOrCreate(
                ['action_plan_id' => $plan->id, 'code' => $plan->code.'-'.Str::slug($current['site'])],
                ['name' => $current['site'], 'position' => $current['row'], 'is_active' => true],
            );
            $counts['axes']++;

            $progress = $current['progress'] ?? $this->averageProgress($current['actions']);
            $project = Project::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'code' => $plan->code.'-R'.$current['row']],
                [
                    'department_id' => $department?->id,
                    'action_plan_id' => $plan->id,
                    'axis_id' => $axis->id,
                    'manager_id' => null,
                    'created_by' => $creator->id,
                    'name' => $this->cleanRubricName($current['name']),
                    'description' => 'Source : PAC SI 26-27 — '.$current['site'],
                    'start_date' => $this->minimumDate($current['actions'], 'start_date'),
                    'due_date' => $this->maximumDate($current['actions'], 'due_date'),
                    'status' => $this->projectStatus($progress, $current['actions']),
                    'priority' => 'high',
                    'progress' => $progress,
                    'is_private' => false,
                    'is_active' => true,
                ],
            );
            $counts['projects']++;

            foreach ($current['actions'] as $position => $actionData) {
                $action = Action::query()->updateOrCreate(
                    ['project_id' => $project->id, 'title' => $actionData['title']],
                    [
                        'organization_id' => $organization->id,
                        'action_plan_id' => $plan->id,
                        'axis_id' => $axis->id,
                        'owner_id' => null,
                        'created_by' => $creator->id,
                        'description' => $actionData['description'],
                        'start_date' => $actionData['start_date'],
                        'due_date' => $actionData['due_date'],
                        'actual_start_date' => $actionData['actual_start_date'],
                        'actual_end_date' => $actionData['actual_end_date'],
                        'status' => $this->actionStatus($actionData['status'], $actionData['progress']),
                        'priority' => 'high',
                        'progress' => $actionData['progress'],
                        'position' => $position,
                    ],
                );
                $counts['actions']++;

                if ($actionData['comment'] !== null) {
                    $comment = ActionComment::query()->firstOrCreate([
                        'action_id' => $action->id,
                        'user_id' => $creator->id,
                        'content' => $actionData['comment'],
                    ]);

                    if ($comment->wasRecentlyCreated) {
                        $counts['comments']++;
                    }
                }
            }

            $current = null;
        };

        foreach ($rows as $rowNumber => $row) {
            if (! empty($row['A']) && ! str_contains(Str::lower($row['A']), 'site')) {
                $site = trim($row['A']);
            }

            if (isset($row['B']) && Str::startsWith(Str::lower(trim($row['B'])), 'rubrique')) {
                $flush();
                $current = ['row' => $rowNumber, 'site' => $site, 'name' => trim($row['B']), 'actions' => [], 'progress' => null];
            }

            if ($current === null) {
                continue;
            }

            if (isset($row['D']) && Str::startsWith(Str::lower(trim($row['D'])), 'statut et avancement')) {
                $current['progress'] = $this->progress($row['Q'] ?? null);
                $flush();

                continue;
            }

            if (! empty($row['D'])) {
                $current['actions'][] = [
                    'title' => trim($row['D']),
                    'description' => $this->description($row, ['G']),
                    'start_date' => $this->excelDate($row['I'] ?? null),
                    'due_date' => $this->excelDate($row['K'] ?? null, true),
                    'actual_start_date' => $this->excelDate($row['M'] ?? null),
                    'actual_end_date' => $this->excelDate($row['S'] ?? null, true),
                    'comment' => $this->comment($row['U'] ?? null),
                    'status' => $row['O'] ?? '',
                    'progress' => $this->progress($row['Q'] ?? null),
                ];
            }
        }

        $flush();
        $this->refreshPlanProgress($plan);
    }

    private function upsertPlan(array $metadata, Organization $organization, ?Department $department, User $creator): ActionPlan
    {
        return ActionPlan::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'code' => $metadata['code']],
            [
                'department_id' => $department?->id,
                'created_by' => $creator->id,
                'name' => $metadata['name'],
                'description' => 'Importé depuis le fichier PAC SI 26-27.xlsx',
                'start_date' => str_contains($metadata['code'], '25-26') ? '2025-09-01' : '2026-09-01',
                'end_date' => str_contains($metadata['code'], '25-26') ? '2026-08-31' : '2027-08-31',
                'status' => 'active',
                'is_active' => true,
            ],
        );
    }

    private function refreshPlanProgress(ActionPlan $plan): void
    {
        $progress = (int) round($plan->projects()->avg('progress') ?? 0);
        $plan->update(['progress' => $progress, 'status' => $progress >= 100 ? 'completed' : 'active']);
    }

    private function cleanRubricName(string $name): string
    {
        return trim((string) preg_replace('/^Rubrique\s+\d+\s*:\s*/iu', '', $name));
    }

    private function progress(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $progress = (float) $value;

        return (int) round(max(0, min(100, $progress <= 1 ? $progress * 100 : $progress)));
    }

    private function projectStatus(int $progress, array $actions): string
    {
        $statuses = Str::lower(Str::ascii(collect($actions)->pluck('status')->implode(' ')));

        return match (true) {
            $progress >= 100 => 'completed',
            str_contains($statuses, 'attente'), str_contains($statuses, 'suspendu') => 'on_hold',
            $progress > 0, str_contains($statuses, 'cours') => 'in_progress',
            default => 'planned',
        };
    }

    private function actionStatus(string $status, int $progress): string
    {
        return match ($this->projectStatus($progress, [['status' => $status]])) {
            'planned' => 'not_started',
            default => $this->projectStatus($progress, [['status' => $status]]),
        };
    }

    private function excelDate(mixed $value, bool $endOfMonth = false): ?string
    {
        if (is_numeric($value) && (float) $value > 0) {
            return CarbonImmutable::create(1899, 12, 30)
                ->addDays((int) floor((float) $value))
                ->toDateString();
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = Str::lower(Str::ascii(trim($value)));

        if (! preg_match('/^(janv?|fev|mars|avr|mai|jui(?:n|l)?|aout|sept?|oct|nov|dec)-(\d{2}|\d{4})$/', $normalized, $matches)) {
            return null;
        }

        $months = [
            'jan' => 1,
            'janv' => 1,
            'fev' => 2,
            'mars' => 3,
            'avr' => 4,
            'mai' => 5,
            'jui' => 6,
            'juin' => 6,
            'juil' => 7,
            'aout' => 8,
            'sep' => 9,
            'sept' => 9,
            'oct' => 10,
            'nov' => 11,
            'dec' => 12,
        ];
        $year = (int) $matches[2];
        $date = CarbonImmutable::create(
            $year < 100 ? 2000 + $year : $year,
            $months[$matches[1]],
            1,
        );

        return ($endOfMonth ? $date->endOfMonth() : $date)->toDateString();
    }

    private function comment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $comment = trim($value);

        return $comment === '' || is_numeric($comment) ? null : $comment;
    }

    private function description(array $row, array $columns): ?string
    {
        $parts = collect($columns)
            ->map(fn (string $column): string => trim($row[$column] ?? ''))
            ->filter()
            ->unique()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode("\n\n");
    }

    private function averageProgress(array $actions): int
    {
        return (int) round(collect($actions)->avg('progress') ?? 0);
    }

    private function minimumDate(array $actions, string $key): ?string
    {
        return collect($actions)->pluck($key)->filter()->min();
    }

    private function maximumDate(array $actions, string $key): ?string
    {
        return collect($actions)->pluck($key)->filter()->max();
    }

    /** @return array<string, array<int, array<string, string>>> */
    private function readWorkbook(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Workbook not found: {$path}");
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open workbook: {$path}");
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $relationships = $this->workbookRelationships($zip);
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheets = [];

            foreach ($workbook->xpath('//m:sheet') as $sheetNode) {
                $relationAttributes = $sheetNode->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $path = $relationships[(string) $relationAttributes['id']] ?? null;

                if ($path !== null) {
                    $sheets[(string) $sheetNode['name']] = $this->sheetRows($zip, $path, $sharedStrings);
                }
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $this->xml($zip, 'xl/sharedStrings.xml');
        $strings = [];

        foreach ($xml->si as $item) {
            $text = (string) $item->t;

            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /** @return array<string, string> */
    private function workbookRelationships(ZipArchive $zip): array
    {
        $xml = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
        $xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationships = [];

        foreach ($xml->xpath('//r:Relationship') as $relationship) {
            $attributes = $relationship->attributes();
            $relationships[(string) $attributes['Id']] = 'xl/'.ltrim((string) $attributes['Target'], '/');
        }

        return $relationships;
    }

    /** @param array<int, string> $sharedStrings
     * @return array<int, array<string, string>>
     */
    private function sheetRows(ZipArchive $zip, string $path, array $sharedStrings): array
    {
        $xml = $this->xml($zip, $path);
        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];

        foreach ($xml->xpath('//m:sheetData/m:row') as $rowNode) {
            $row = [];

            foreach ($rowNode->c as $cell) {
                $column = preg_replace('/\d+/', '', (string) $cell['r']);
                $value = (string) $cell->v;

                if ((string) $cell['t'] === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ((string) $cell['t'] === 'inlineStr') {
                    $value = (string) $cell->is->t;
                }

                if ($value !== '') {
                    $row[$column] = $value;
                }
            }

            if ($row !== []) {
                $rows[(int) $rowNode['r']] = $row;
            }
        }

        return $rows;
    }

    private function xml(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $contents = $zip->getFromName($path);

        if ($contents === false) {
            throw new RuntimeException("Missing workbook part: {$path}");
        }

        return new SimpleXMLElement($contents);
    }
}
