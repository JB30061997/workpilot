<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('department_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->boolean('is_manager')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamp('joined_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['department_id', 'user_id'],
                'department_user_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_members');
    }
};