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
        Schema::create('gmail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gmail_integration_id')->constrained()->cascadeOnDelete();
            $table->string('gmail_message_id');
            $table->string('thread_id')->nullable();
            $table->string('ticket_number')->nullable()->index();
            $table->string('event_type')->nullable();
            $table->string('sender')->nullable();
            $table->string('sender_email')->nullable();
            $table->string('subject', 1000);
            $table->text('snippet')->nullable();
            $table->timestamp('received_at')->nullable()->index();
            $table->boolean('is_unread')->default(false);
            $table->timestamps();

            $table->unique(['gmail_integration_id', 'gmail_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gmail_messages');
    }
};
