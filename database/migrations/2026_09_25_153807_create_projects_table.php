<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('action_plan_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('axis_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('manager_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('code')->nullable();

            $table->text('description')->nullable();

            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();

            $table->enum('status', [
                'draft',
                'planned',
                'in_progress',
                'on_hold',
                'completed',
                'cancelled'
            ])->default('draft');

            $table->enum('priority', [
                'low',
                'medium',
                'high',
                'critical'
            ])->default('medium');

            $table->unsignedTinyInteger('progress')->default(0);

            $table->boolean('is_private')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['department_id', 'status']);
            $table->index('manager_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};