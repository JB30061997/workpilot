<?php

namespace App\Services;

use App\Models\Task;

class TaskPlanningSynchronizer
{
    public function sync(Task $task): void
    {
        $automaticPlannings = $task->plannings()
            ->where('is_automatic', true)
            ->orderBy('id')
            ->get();

        if ($task->start_at === null || $task->due_at === null) {
            $automaticPlannings->each->delete();

            return;
        }

        $attributes = [
            'organization_id' => $task->organization_id,
            'department_id' => $task->department_id,
            'created_by' => $task->created_by,
            'start_at' => $task->start_at,
            'end_at' => $task->due_at,
            'is_automatic' => true,
        ];

        $automaticPlanning = $automaticPlannings->shift();

        if ($automaticPlanning === null) {
            $task->plannings()->create($attributes);
        } else {
            $automaticPlanning->update($attributes);
        }

        $automaticPlannings->each->delete();
    }
}
