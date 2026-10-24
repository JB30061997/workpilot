<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectChatAttachment extends Model
{
    protected $fillable = [
        'message_id',
        'original_name',
        'disk',
        'path',
        'mime_type',
        'size',
    ];

    public function message()
    {
        return $this->belongsTo(ProjectChatMessage::class, 'message_id');
    }
}
