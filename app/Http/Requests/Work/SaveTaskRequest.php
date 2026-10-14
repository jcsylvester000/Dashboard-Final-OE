<?php

namespace App\Http\Requests\Work;

use App\Models\Task;
use App\Models\Workspace;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create (POST) or partially update (PUT) a task. On update only the fields
 * sent are validated and changed, so the board and inline edits can send one field.
 */
class SaveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');
        /** @var Task|null $task */
        $task = $this->route('task');

        return $task === null
            ? Gate::allows('contribute', $workspace)
            : Gate::allows('update', $task);
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('work_details'))) {
            // Drop empty values so clearing a field removes it.
            $this->merge(['work_details' => array_filter((array) $this->input('work_details'), fn ($v) => $v !== null && $v !== '') ?: null]);
        }

        // Estimates are entered in hours (decimals allowed) and stored as minutes.
        if ($this->has('estimate_hours')) {
            $hours = $this->input('estimate_hours');
            $this->merge(['estimate_minutes' => is_numeric($hours) ? (int) round((float) $hours * 60) : null]);
        }

        if (is_array($this->input('custom_values'))) {
            $this->merge(['custom_values' => array_filter((array) $this->input('custom_values'), fn ($v) => $v !== null && $v !== '') ?: null]);
        }

        if ($this->has('label_ids')) {
            $this->merge(['label_ids' => array_values(array_filter((array) $this->input('label_ids')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');
        $creating = $this->route('task') === null;
        $req = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'status_id' => ['sometimes', 'integer', Rule::exists('task_statuses', 'id')],
            'priority' => ['sometimes', Rule::in(array_keys(Task::PRIORITIES))],
            'type' => ['sometimes', Rule::in(array_keys(Task::TYPES))],
            'sprint_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sprints', 'id')->where('workspace_id', $workspace->id)->whereNot('status', 'closed')],
            'story_points' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'recurrence' => ['sometimes', 'nullable', Rule::in(array_keys(Task::RECURRENCES))],
            'recurrence_until' => ['sometimes', 'nullable', 'date'],
            // B-12: when the editor opened the task; a newer save by someone else is a conflict.
            '_seen_at' => ['sometimes', 'nullable', 'date'],
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('workspace_id', $workspace->id)->whereNull('deleted_at')],
            'assignee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id)],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tasks', 'id')->where('workspace_id', $workspace->id)->whereNull('deleted_at')],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'start_on' => ['sometimes', 'nullable', 'date', function (string $attribute, mixed $value, Closure $fail) {
                // Compare with the due date being saved, or the one the task already has.
                $task = $this->route('task');
                $due = $this->has('due_on') ? $this->input('due_on') : ($task instanceof Task ? $task->due_on?->toDateString() : null);
                if ($value !== null && is_string($due) && $due !== '' && (string) $value > $due) {
                    $fail(__('The start date must be on or before the due date.'));
                }
            }],
            'estimate_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'work_details' => ['sometimes', 'nullable', 'array:'.implode(',', $this->workDetailKeys())],
            'work_details.*' => ['nullable', 'string', 'max:255'],
            'work_details.target_url' => ['nullable', 'url:http,https', 'max:255'],
            ...$this->workDetailSelectRules(),
            ...$this->customFieldRules($workspace),
            // E-21: structured bug report (steps, expected, actual are the minimum).
            'bug_report' => ['sometimes', 'nullable', 'array:steps,expected,actual,environment,severity'],
            'bug_report.steps' => ['required_with:bug_report', 'string', 'max:5000'],
            'bug_report.expected' => ['required_with:bug_report', 'string', 'max:2000'],
            'bug_report.actual' => ['required_with:bug_report', 'string', 'max:2000'],
            'bug_report.environment' => ['nullable', 'string', 'max:500'],
            'bug_report.severity' => ['nullable', Rule::in(array_keys(Task::SEVERITIES))],
            'label_ids' => ['sometimes', 'array', 'max:30'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')->where('workspace_id', $workspace->id)],
        ];
    }

    /**
     * B-07: values for this client's custom fields, typed per field; unknown keys refused.
     *
     * @return array<string, array<int, mixed>|string>
     */
    private function customFieldRules(Workspace $workspace): array
    {
        $fields = $workspace->custom_fields ?? [];
        $rules = ['custom_values' => ['sometimes', 'nullable', 'array:'.implode(',', array_column($fields, 'key'))]];
        foreach ($fields as $f) {
            $rules['custom_values.'.$f['key']] = match ($f['type']) {
                'number' => ['nullable', 'numeric', 'between:-999999999,999999999'],
                'date' => ['nullable', 'date'],
                'checkbox' => ['nullable', 'boolean'],
                'url' => ['nullable', 'url:http,https', 'max:500'],
                'select' => ['nullable', Rule::in($f['options'] ?? [])],
                default => ['nullable', 'string', 'max:500'],
            };
        }

        return $rules;
    }

    /**
     * Select-type work detail fields must use one of their listed options.
     *
     * @return array<string, array<int, mixed>>
     */
    private function workDetailSelectRules(): array
    {
        $rules = [];
        foreach (Task::WORK_DETAIL_FIELDS as $fields) {
            foreach ($fields as $key => $def) {
                if ($def[1] === 'select') {
                    $rules['work_details.'.$key] = ['nullable', Rule::in($def[2])];
                }
                if ($def[1] === 'multiselect') {
                    $options = $def[2];
                    $rules['work_details.'.$key] = ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail) use ($options): void {
                        foreach (array_map('trim', explode(',', (string) $value)) as $part) {
                            if ($part !== '' && ! in_array($part, $options, true)) {
                                $fail(__('":value" is not one of the options.', ['value' => $part]));
                            }
                        }
                    }];
                }
            }
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function workDetailKeys(): array
    {
        $keys = [];
        foreach (Task::WORK_DETAIL_FIELDS as $fields) {
            $keys = [...$keys, ...array_keys($fields)];
        }

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignee_id.exists' => __('The assignee must be a member of this workspace.'),
            'project_id.exists' => __('Choose a project from this workspace.'),
            'sprint_id.exists' => __('Choose a planned or active sprint of this client.'),
        ];
    }
}
