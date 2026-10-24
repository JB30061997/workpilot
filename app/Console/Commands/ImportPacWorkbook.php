<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Services\PacWorkbookImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportPacWorkbook extends Command
{
    protected $signature = 'workpilot:import-pac
                            {file : Absolute path to the PAC Excel workbook}
                            {--organization= : Organization ID; defaults to the first organization}';

    protected $description = 'Import PAC action plans and projects from an Excel workbook';

    public function handle(PacWorkbookImporter $importer): int
    {
        $organization = $this->option('organization')
            ? Organization::query()->find($this->option('organization'))
            : Organization::query()->oldest('id')->first();

        if (! $organization) {
            $this->error('No matching organization was found.');

            return self::FAILURE;
        }

        $creator = User::query()->where('email', 'admin@workpilot.local')->first()
            ?? User::query()->oldest('id')->first();

        if (! $creator) {
            $this->error('No user is available as the import creator.');

            return self::FAILURE;
        }

        try {
            $counts = $importer->import((string) $this->argument('file'), $organization, $creator);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('PAC workbook imported successfully.');
        $this->table(['Plans', 'Axes', 'Projects', 'Actions', 'Comments'], [array_values($counts)]);

        return self::SUCCESS;
    }
}
