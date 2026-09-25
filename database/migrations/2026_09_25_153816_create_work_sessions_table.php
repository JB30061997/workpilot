<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sessions', function (Blueprint $table) {
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

            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('name');

            $table->text('description')->nullable();

            $table->date('start_date');
            $table->date('end_date');

            $table->enum('visibility', [
                'private',
                'department',
                'organization'
            ])->default('private');

            $table->enum('status', [
                'draft',
                'active',
                'completed',
                'archived'
            ])->default('draft');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['organization_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_sessions');
    }
};