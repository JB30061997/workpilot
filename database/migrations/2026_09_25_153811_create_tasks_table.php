<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('project_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('action_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('status_id')
                ->constrained('task_statuses')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('tasks')
                ->cascadeOnDelete();

            $table->string('title');

            $table->longText('description')->nullable();

            $table->enum('priority', [
                'low',
                'medium',
                'high',
                'critical'
            ])->default('medium');

            $table->dateTime('start_at')->nullable();
            $table->dateTime('due_at')->nullable();

            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->unsignedInteger('actual_minutes')->default(0);

            $table->unsignedTinyInteger('progress')->default(0);

            $table->unsignedInteger('position')->default(0);

            $table->timestamp('completed_at')->nullable();

            $table->boolean('is_private')->default(false);

            $table->timestamps();

            $table->index(['organization_id', 'status_id']);
            $table->index(['project_id', 'status_id']);
            $table->index(['department_id', 'status_id']);
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};