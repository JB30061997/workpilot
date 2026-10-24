<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'department_id', 'base_url', 'api_version', 'app_token',
    'user_token', 'entity_id', 'is_active', 'last_tested_at', 'last_test_status',
    'last_test_message',
])]
#[Hidden(['app_token', 'user_token'])]
class GlpiIntegration extends Model
{
    protected function casts(): array
    {
        return [
            'app_token' => 'encrypted',
            'user_token' => 'encrypted',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
