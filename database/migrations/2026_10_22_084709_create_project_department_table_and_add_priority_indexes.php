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
        Schema::create('project_department', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['project_id', 'department_id']);
            $table->index(['department_id', 'project_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('priority');
            $table->index(['axis_id', 'position']);
        });

        DB::table('projects')
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->each(function (object $project): void {
                DB::table('project_department')->insertOrIgnore([
                    'project_id' => $project->id,
                    'department_id' => $project->department_id,
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
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['axis_id', 'position']);
            $table->dropColumn('position');
        });

        Schema::dropIfExists('project_department');
    }
};
