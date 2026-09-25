<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('work_session_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('role', [
                'owner',
                'member',
                'viewer'
            ])->default('member');

            $table->timestamp('joined_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['work_session_id', 'user_id'],
                'session_user_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_members');
    }
};