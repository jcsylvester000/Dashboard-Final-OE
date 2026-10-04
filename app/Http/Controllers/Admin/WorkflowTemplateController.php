<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agency-wide cross-department workflow templates (Admin).
 */
class WorkflowTemplateController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(): Response
    {
        return Inertia::render('admin/templates/Index', [
            'templates' => WorkflowTemplate::with('steps')->orderBy('name')->get()
                ->map(fn (WorkflowTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'is_active' => $t->is_active,
                    'steps' => $t->steps->map(fn (WorkflowTemplateStep $s) => [
                        'department_id' => $s->department_id,
                        'title' => $s->title,
                        'description' => $s->description,
                        'offset_days' => $s->offset_days,
                        'depends_on_previous' => $s->depends_on_previous,
                    ])->values(),
                ]),
            'departments' => Department::query()->orderBy('position')->get(['id', 'name', 'color']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request) {
            $template = WorkflowTemplate::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $request->user()->id,
            ]);
            $this->syncSteps($template, $data['steps']);
            $this->activity->log('workflow-template.created', $template);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template created.')]);

        return back();
    }

    public function update(Request $request, WorkflowTemplate $template): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($template, $data) {
            $template->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ])->save();
            $this->syncSteps($template, $data['steps']);
            $this->activity->log('workflow-template.updated', $template);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return back();
    }

    public function destroy(WorkflowTemplate $template): RedirectResponse
    {
        // Tasks already created from it are unaffected.
        $template->delete();
        $this->activity->log('workflow-template.deleted', null, ['name' => $template->name]);

        return back();
    }

    /**
     * @return array{name: string, description?: string|null, is_active?: bool, steps: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
        /** @var array{name: string, description?: string|null, is_active?: bool, steps: list<array<string, mixed>>} */
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:30'],
            'steps.*.department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'steps.*.title' => ['required', 'string', 'max:255'],
            'steps.*.description' => ['nullable', 'string', 'max:2000'],
            'steps.*.offset_days' => ['required', 'integer', 'min:0', 'max:365'],
            'steps.*.depends_on_previous' => ['boolean'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function syncSteps(WorkflowTemplate $template, array $steps): void
    {
        $template->steps()->delete();

        foreach ($steps as $i => $step) {
            $template->steps()->create([
                'position' => $i,
                'department_id' => $step['department_id'] ?? null,
                'title' => $step['title'],
                'description' => $step['description'] ?? null,
                'offset_days' => (int) $step['offset_days'],
                'depends_on_previous' => $i > 0 && (bool) ($step['depends_on_previous'] ?? true),
            ]);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'template';
        $slug = $base;
        $n = 2;
        while (WorkflowTemplate::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
