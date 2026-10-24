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
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('work_session_id')
                ->nullable()
                ->after('action_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['work_session_id', 'status_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['work_session_id', 'status_id']);
            $table->dropConstrainedForeignId('work_session_id');
        });
    }
};
