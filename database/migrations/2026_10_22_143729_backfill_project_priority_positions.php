<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $positions = [];

        DB::table('projects')
            ->orderBy('organization_id')
            ->orderBy('action_plan_id')
            ->orderBy('axis_id')
            ->orderBy('id')
            ->each(function (object $project) use (&$positions): void {
                $group = implode(':', [
                    $project->organization_id,
                    $project->action_plan_id ?? 'none',
                    $project->axis_id ?? 'none',
                ]);
                $positions[$group] = ($positions[$group] ?? 0) + 1;

                DB::table('projects')
                    ->where('id', $project->id)
                    ->update(['position' => $positions[$group]]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Existing priority positions cannot be restored reliably.
    }
};
