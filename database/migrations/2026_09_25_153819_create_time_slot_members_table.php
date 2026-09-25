<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_slot_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('time_slot_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['time_slot_id', 'user_id'],
                'time_slot_user_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_slot_members');
    }
};