<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentMember extends Model
{
    protected $fillable = [
        'department_id',
        'user_id',
        'is_manager',
        'is_primary',
        'is_active',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'is_manager' => 'boolean',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}