<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowNode extends Model
{
    protected $fillable = ['workflow_definition_id', 'node_key', 'type', 'label', 'configuration', 'position_x', 'position_y'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'position_x' => 'float', 'position_y' => 'float'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
