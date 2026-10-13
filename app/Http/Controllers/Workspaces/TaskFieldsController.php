<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\TaskStatus;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * B-07: per-client custom task fields and names for the shared statuses. Leads manage
 * both. Field keys are generated once and never change, so renaming a field keeps the
 * values already saved on tasks; removing a field hides its values (they stay on the task).
 */
class TaskFieldsController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function fields(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $data = $request->validate([
            'fields' => ['present', 'array', 'max:'.Workspace::MAX_CUSTOM_FIELDS],
            'fields.*.key' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'fields.*.label' => ['required', 'string', 'max:40', 'distinct:ignore_case'],
            'fields.*.type' => ['required', Rule::in(array_keys(Workspace::CUSTOM_FIELD_TYPES))],
            'fields.*.options' => ['nullable', 'array', 'max:30'],
            // Blank lines arrive as null (ConvertEmptyStringsToNull) and are dropped below.
            'fields.*.options.*' => ['nullable', 'string', 'max:60'],
        ], ['fields.*.label.distinct' => __('Two fields have the same name.')]);

        $existing = collect($workspace->custom_fields ?? [])->pluck('key')->all();
        $fields = [];
        foreach ($data['fields'] as $i => $f) {
            $key = isset($f['key']) && in_array($f['key'], $existing, true) ? $f['key'] : $this->newKey((string) $f['label'], [...$existing, ...array_column($fields, 'key')]);
            $field = ['key' => $key, 'label' => trim((string) $f['label']), 'type' => (string) $f['type']];
            if ($f['type'] === 'select') {
                $options = array_values(array_unique(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($f['options'] ?? [])), fn ($o) => $o !== '')));
                if ($options === []) {
                    throw ValidationException::withMessages(["fields.$i.options" => __('Add at least one option for ":label".', ['label' => $field['label']])]);
                }
                $field['options'] = $options;
            }
            $fields[] = $field;
        }

        $workspace->forceFill(['custom_fields' => $fields === [] ? null : $fields])->save();
        $this->activity->log('workspace.custom-fields', $workspace, ['count' => count($fields)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task fields saved.')]);

        return back();
    }

    public function statusLabels(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $ids = TaskStatus::ordered()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $data = $request->validate([
            'labels' => ['present', 'array'],
            'labels.*' => ['nullable', 'string', 'max:30'],
        ]);

        $labels = [];
        foreach ((array) $data['labels'] as $id => $label) {
            $label = trim((string) $label);
            if (in_array((string) $id, $ids, true) && $label !== '') {
                $labels[(string) $id] = $label;
            }
        }

        $workspace->forceFill(['status_labels' => $labels === [] ? null : $labels])->save();
        $this->activity->log('workspace.status-labels', $workspace, ['labels' => $labels]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status names saved.')]);

        return back();
    }

    /**
     * @param  array<int, mixed>  $taken
     */
    private function newKey(string $label, array $taken): string
    {
        $base = Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(30, '')->toString() ?: 'field';
        $key = $base;
        for ($n = 2; in_array($key, $taken, true); $n++) {
            $key = $base.'_'.$n;
        }

        return $key;
    }
}
