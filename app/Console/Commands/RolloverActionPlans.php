<?php

namespace App\Console\Commands;

use App\Services\ActionPlanYearRolloverService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('action-plans:rollover {--organization= : Limit the rollover to one organization ID}')]
#[Description('Create the current planning year and move non-started projects from the previous year')]
class RolloverActionPlans extends Command
{
    public function handle(ActionPlanYearRolloverService $rolloverService): int
    {
        $organizationId = $this->option('organization');
        $result = $rolloverService->rollover(
            organizationId: $organizationId !== null ? (int) $organizationId : null
        );

        $this->components->info(sprintf(
            '%d année(s) créée(s), %d plan(s) créé(s), %d projet(s) déplacé(s).',
            $result['years_created'],
            $result['plans_created'],
            $result['projects_moved']
        ));

        return self::SUCCESS;
    }
}
