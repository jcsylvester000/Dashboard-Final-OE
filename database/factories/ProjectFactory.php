<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => rtrim(fake()->sentence(3), '.'),
            'type' => 'project',
            'status' => 'active',
            'description' => null,
            'start_on' => null,
            'due_on' => null,
        ];
    }
}
