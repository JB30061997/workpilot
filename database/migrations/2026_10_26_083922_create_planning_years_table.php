<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('planning_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('label', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->unique(['organization_id', 'start_date']);
            $table->unique(['organization_id', 'end_date']);
            $table->index(['organization_id', 'start_date', 'end_date']);
        });

        $today = now();
        $startYear = $today->month >= 9 ? $today->year : $today->year - 1;

        DB::table('organizations')
            ->orderBy('id')
            ->each(function (object $organization) use ($startYear): void {
                DB::table('planning_years')->insert([
                    'organization_id' => $organization->id,
                    'label' => $startYear.'-'.($startYear + 1),
                    'start_date' => $startYear.'-09-01',
                    'end_date' => ($startYear + 1).'-08-31',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planning_years');
    }
};
