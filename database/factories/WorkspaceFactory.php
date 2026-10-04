<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'industry' => fake()->randomElement(['Retail', 'Real estate', 'Hospitality', 'SaaS']),
            'website' => null,
            'status' => 'active',
            'color' => 'blue',
            'description' => null,
            'primary_contact_name' => null,
        ];
    }

    /**
     * Add a member with a workspace role after creating.
     */
    public function withMember(User $user, string $role = Workspace::ROLE_MEMBER, ?int $departmentId = null): static
    {
        return $this->afterCreating(fn (Workspace $workspace) => $workspace->members()->attach($user->id, [
            'role' => $role,
            'department_id' => $departmentId,
        ]));
    }
}
