<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'status_id' => fn () => TaskStatus::idFor('todo'),
            'title' => rtrim(fake()->sentence(4), '.'),
            'priority' => 'normal',
            'position' => 0,
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status_id' => TaskStatus::idFor('done'),
            'completed_at' => now(),
        ]);
    }
}
