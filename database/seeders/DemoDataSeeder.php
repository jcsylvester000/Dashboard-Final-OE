<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Realistic volume for local / staging QA and performance checks (QA checklist):
 * 12 team members across the 5 departments, 3 clients, ~500 tasks and ~5,000 time entries.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Refuses to run in production. Demo users have password "password" and are inactive-safe to delete.
 */
class DemoDataSeeder extends Seeder
{
    public const TASKS = 500;

    public const ENTRIES = 5000;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoDataSeeder must never run in production.');
        }

        $this->call([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        $departments = Department::query()->orderBy('position')->get();
        $statuses = TaskStatus::ordered();
        $done = $statuses->firstWhere('slug', 'done');

        $people = collect();
        foreach ($departments as $dept) {
            $count = in_array($dept->slug, ['development', 'marketing'], true) ? 3 : 2;
            for ($i = 1; $i <= $count; $i++) {
                $user = User::factory()->withRole($i === 1 ? 'dept-lead' : 'member')->create([
                    'name' => "Demo {$dept->name} {$i}",
                    'email' => "demo.{$dept->slug}.{$i}@overeasy.local",
                    'primary_department_id' => $dept->id,
                ]);
                $user->departments()->syncWithoutDetaching([$dept->id]);
                if ($i === 1) {
                    $dept->update(['lead_user_id' => $user->id]);
                }
                $people->push($user);
            }
        }

        $clients = collect(['Demo Brewhouse', 'Demo Realty', 'Demo Dental'])->map(fn (string $name) => Workspace::factory()->create(['name' => $name]));

        foreach ($clients as $ws) {
            foreach ($people as $index => $person) {
                $ws->members()->syncWithoutDetaching([$person->id => ['role' => $index % 6 === 0 ? Workspace::ROLE_LEAD : Workspace::ROLE_MEMBER]]);
            }
            Project::factory()->count(3)->create(['workspace_id' => $ws->id]);
        }

        $tasks = [];
        for ($i = 0; $i < self::TASKS; $i++) {
            $ws = $clients[$i % $clients->count()];
            $dept = $departments[$i % $departments->count()];
            $assignee = $people->where('primary_department_id', $dept->id)->random();
            $status = $statuses->random();
            $created = now()->subDays(random_int(0, 90));
            $isDone = $status->id === $done?->id;

            $tasks[] = Task::factory()->create([
                'workspace_id' => $ws->id,
                'department_id' => $dept->id,
                'assignee_id' => $assignee->id,
                'reporter_id' => $people->random()->id,
                'status_id' => $status->id,
                'priority' => ['low', 'normal', 'normal', 'high', 'urgent'][random_int(0, 4)],
                'due_on' => random_int(0, 4) === 0 ? null : now()->addDays(random_int(-20, 30))->toDateString(),
                'created_at' => $created,
                'completed_at' => $isDone ? $created->addDays(random_int(1, 10)) : null,
                'work_details' => match ($dept->slug) {
                    'marketing' => ['channel' => 'Facebook', 'campaign' => 'Q4 promo', 'deliverable_type' => 'Ad creative'],
                    'seo' => ['target_url' => 'https://example.com/menu', 'keyword' => 'best coffee', 'work_type' => 'On-page'],
                    default => null,
                },
            ]);
        }

        // Time entries in bulk (approved ~70%).
        $rows = [];
        for ($i = 0; $i < self::ENTRIES; $i++) {
            $task = $tasks[array_rand($tasks)];
            $date = now()->subDays(random_int(0, 60))->toDateString();
            $rows[] = [
                'user_id' => $task->assignee_id,
                'workspace_id' => $task->workspace_id,
                'task_id' => $task->id,
                'department_id' => $task->department_id,
                'entry_date' => $date,
                'minutes' => [15, 30, 45, 60, 90, 120][random_int(0, 5)],
                'is_billable' => random_int(0, 9) > 0,
                'approved_at' => random_int(0, 9) < 7 ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($rows) === 500) {
                DB::table('time_entries')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('time_entries')->insert($rows);
        }
    }
}
