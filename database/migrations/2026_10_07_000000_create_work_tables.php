<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One shared set of statuses for every department (reference data, seeded here).
        Schema::create('task_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('slug', 40)->unique();
            $table->string('category', 20); // open | active | blocked | done
            $table->string('color', 20)->default('slate');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('task_statuses')->insert([
            ['name' => 'Backlog', 'slug' => 'backlog', 'category' => 'open', 'color' => 'slate', 'position' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'To Do', 'slug' => 'todo', 'category' => 'open', 'color' => 'blue', 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'In Progress', 'slug' => 'in-progress', 'category' => 'active', 'color' => 'violet', 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'In Review', 'slug' => 'in-review', 'category' => 'active', 'color' => 'amber', 'position' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Blocked', 'slug' => 'blocked', 'category' => 'blocked', 'color' => 'rose', 'position' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Done', 'slug' => 'done', 'category' => 'done', 'color' => 'emerald', 'position' => 5, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // The unified work item used by Development, Research, Product, Marketing and SEO.
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('status_id')->constrained('task_statuses');
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('handoff_from_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('normal'); // low | normal | high | urgent
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable()->index();
            $table->unsignedInteger('estimate_minutes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status_id']);
            $table->index(['assignee_id', 'status_id']);
            $table->index(['department_id', 'status_id']);
            $table->index(['project_id', 'status_id']);
        });

        // task_id cannot be finished until depends_on_task_id is done. type: blocks | handoff
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('type', 20)->default('blocks');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'depends_on_task_id']);
        });

        Schema::create('task_watchers', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'user_id']);
        });

        // Discussion on tasks (and projects later). Mentions are synced from the body.
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->morphs('commentable');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Agency-wide reusable cross-department workflows.
        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('workflow_template_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('offset_days')->default(0); // due = start + offset
            $table->boolean('depends_on_previous')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_template_steps');
        Schema::dropIfExists('workflow_templates');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('task_watchers');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_statuses');
    }
};
