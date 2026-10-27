<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('action_plans', function (Blueprint $table) {
            $table->foreignId('planning_year_id')->nullable()->after('department_id')->constrained('planning_years')->nullOnDelete();
            $table->foreignId('rollover_source_id')->nullable()->after('planning_year_id')->constrained('action_plans')->nullOnDelete();
            $table->unique(['planning_year_id', 'rollover_source_id'], 'action_plans_year_rollover_source_unique');
        });

        DB::table('action_plans')
            ->select(['id', 'organization_id', 'start_date', 'created_at'])
            ->orderBy('id')
            ->each(function (object $plan): void {
                $referenceDate = Carbon::parse($plan->start_date ?? $plan->created_at ?? now());
                $startYear = $referenceDate->month >= 9 ? $referenceDate->year : $referenceDate->year - 1;
                $startDate = $startYear.'-09-01';
                $endDate = ($startYear + 1).'-08-31';

                DB::table('planning_years')->insertOrIgnore([
                    'organization_id' => $plan->organization_id,
                    'label' => $startYear.'-'.($startYear + 1),
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $planningYearId = DB::table('planning_years')
                    ->where('organization_id', $plan->organization_id)
                    ->where('start_date', $startDate)
                    ->value('id');

                DB::table('action_plans')->where('id', $plan->id)->update([
                    'planning_year_id' => $planningYearId,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('action_plans', function (Blueprint $table) {
            $table->dropUnique('action_plans_year_rollover_source_unique');
            $table->dropConstrainedForeignId('rollover_source_id');
            $table->dropConstrainedForeignId('planning_year_id');
        });
    }
};
