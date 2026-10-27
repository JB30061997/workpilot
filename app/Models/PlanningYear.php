<?php

namespace App\Models;

use Database\Factories\PlanningYearFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class PlanningYear extends Model
{
    /** @use HasFactory<PlanningYearFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'label',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** @return array{label: string, start_date: Carbon, end_date: Carbon} */
    public static function boundsFor(Carbon $date): array
    {
        $startYear = $date->month >= 9 ? $date->year : $date->year - 1;

        return [
            'label' => $startYear.'-'.($startYear + 1),
            'start_date' => Carbon::create($startYear, 9, 1)->startOfDay(),
            'end_date' => Carbon::create($startYear + 1, 8, 31)->startOfDay(),
        ];
    }

    public static function resolveForOrganization(int $organizationId, Carbon $date): self
    {
        $bounds = self::boundsFor($date);

        $planningYear = self::query()
            ->where('organization_id', $organizationId)
            ->whereDate('start_date', $bounds['start_date']->toDateString())
            ->first();

        return $planningYear ?? self::query()->create([
            'organization_id' => $organizationId,
            'label' => $bounds['label'],
            'start_date' => $bounds['start_date']->toDateString(),
            'end_date' => $bounds['end_date']->toDateString(),
        ]);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actionPlans(): HasMany
    {
        return $this->hasMany(ActionPlan::class);
    }
}
