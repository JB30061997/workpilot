<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'gmail_integration_id', 'gmail_message_id', 'thread_id', 'ticket_number',
    'event_type', 'sender', 'sender_email', 'subject', 'snippet', 'received_at',
    'is_unread',
])]
class GmailMessage extends Model
{
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'is_unread' => 'boolean',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(GmailIntegration::class, 'gmail_integration_id');
    }
}
