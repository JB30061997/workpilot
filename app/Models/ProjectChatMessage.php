<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectChatMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'user_id',
        'body',
        'reply_to_id',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attachments()
    {
        return $this->hasMany(ProjectChatAttachment::class, 'message_id');
    }

    public function replyTo()
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }
}
