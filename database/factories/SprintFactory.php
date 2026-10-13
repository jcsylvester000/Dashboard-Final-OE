<?php

namespace Database\Factories;

use App\Models\Sprint;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sprint>
 */
class SprintFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => 'Sprint '.fake()->numberBetween(1, 50),
            'goal' => null,
            'starts_on' => now()->startOfWeek()->toDateString(),
            'ends_on' => now()->startOfWeek()->addDays(11)->toDateString(),
        ];
    }
}
