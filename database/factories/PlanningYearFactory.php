<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\PlanningYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanningYear>
 */
class PlanningYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'label' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
        ];
    }
}
