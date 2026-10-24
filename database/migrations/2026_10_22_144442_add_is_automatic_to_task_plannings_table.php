<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('task_plannings', function (Blueprint $table) {
            $table->boolean('is_automatic')->default(false)->after('end_at');
            $table->index(['task_id', 'is_automatic']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_plannings', function (Blueprint $table) {
            $table->dropIndex(['task_id', 'is_automatic']);
            $table->dropColumn('is_automatic');
        });
    }
};
