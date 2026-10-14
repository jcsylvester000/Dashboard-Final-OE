<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public const COLORS = ['slate', 'blue', 'violet', 'emerald', 'amber', 'rose', 'cyan', 'orange'];

    public function index(): Response
    {
        return Inertia::render('admin/departments/Index', [
            'departments' => Department::with('lead:id,name')
                ->withCount(['tasks as open_tasks' => fn ($q) => $q->whereNull('completed_at')])
                ->withCount(['members' => fn ($q) => $q->where('is_active', true)])
                ->orderBy('position')
                ->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'slug' => $d->slug,
                    'color' => $d->color,
                    'description' => $d->description,
                    'lead_user_id' => $d->lead_user_id,
                    'lead' => $d->lead?->name,
                    'members' => $d->members_count,
                    'open_tasks' => (int) $d->getAttribute('open_tasks'),
                    'archived' => $d->archived_at !== null,
                ]),
            'leads' => User::active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]),
            'colors' => self::COLORS,
        ]);
    }

    public function store(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $this->validated($request);

        $department = Department::create([
            ...$data,
            'slug' => $this->uniqueSlug($data['name']),
            'position' => (int) Department::max('position') + 1,
        ]);

        $activity->log('department.created', $department);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Department added.')]);

        return back();
    }

    public function update(Request $request, Department $department, ActivityLogger $activity): RedirectResponse
    {
        $department->fill($this->validated($request, $department))->save();

        $activity->log('department.updated', $department, ['changed' => array_keys($department->getChanges())]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Department saved.')]);

        return back();
    }

    /**
     * E-94: archive a department. Its people and open tasks move to another department
     * first; finished work and time keep the old department so reports stay true.
     */
    public function archive(Request $request, Department $department, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'move_to' => ['required', 'integer', Rule::exists('departments', 'id')->whereNull('archived_at'), Rule::notIn([$department->id])],
        ]);
        abort_if($department->archived_at !== null, 422, 'Already archived.');
        $to = Department::query()->findOrFail((int) $data['move_to']);

        $moved = DB::transaction(function () use ($department, $to) {
            $memberIds = $department->members()->pluck('users.id')->all();
            $to->members()->syncWithoutDetaching($memberIds);
            $department->members()->detach();
            $primary = User::query()->where('primary_department_id', $department->id)->update(['primary_department_id' => $to->id]);
            $tasks = Task::query()->where('department_id', $department->id)->whereNull('completed_at')->update(['department_id' => $to->id]);
            $department->forceFill(['archived_at' => now(), 'lead_user_id' => null])->save();

            return ['members' => count($memberIds), 'primary' => $primary, 'tasks' => $tasks];
        });

        $activity->log('department.archived', $department, ['moved_to' => $to->name, ...$moved]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':d archived. :m people and :t open tasks moved to :to.', ['d' => $department->name, 'm' => $moved['members'], 't' => $moved['tasks'], 'to' => $to->name])]);

        return back();
    }

    public function restore(Department $department, ActivityLogger $activity): RedirectResponse
    {
        $department->forceFill(['archived_at' => null])->save();
        $activity->log('department.restored', $department);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Department $department = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('departments', 'name')->ignore($department?->id)],
            'color' => ['required', Rule::in(self::COLORS)],
            'description' => ['nullable', 'string', 'max:255'],
            'lead_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'department';
        $slug = $base;
        $i = 2;
        while (Department::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
