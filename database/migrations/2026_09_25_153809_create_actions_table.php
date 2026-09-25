<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('project_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('action_plan_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('axis_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('owner_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('title');

            $table->text('description')->nullable();

            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();

            $table->enum('status', [
                'not_started',
                'in_progress',
                'on_hold',
                'completed',
                'cancelled'
            ])->default('not_started');

            $table->enum('priority', [
                'low',
                'medium',
                'high',
                'critical'
            ])->default('medium');

            $table->unsignedTinyInteger('progress')->default(0);

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};