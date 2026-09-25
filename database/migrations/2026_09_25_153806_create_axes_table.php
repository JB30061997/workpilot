<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('axes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('action_plan_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('code')->nullable();

            $table->text('description')->nullable();

            $table->unsignedInteger('position')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['action_plan_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('axes');
    }
};