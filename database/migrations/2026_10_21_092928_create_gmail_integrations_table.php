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
        Schema::create('gmail_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->string('label_name')->default('GLPI_Tickets');
            $table->string('label_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status', 20)->nullable();
            $table->text('last_sync_message')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'department_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gmail_integrations');
    }
};
