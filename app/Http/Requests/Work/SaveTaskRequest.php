<?php

namespace App\Http\Requests\Work;

use App\Models\Task;
use App\Models\Workspace;
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
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('workspace_id', $workspace->id)->whereNull('deleted_at')],
            'assignee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id)],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tasks', 'id')->where('workspace_id', $workspace->id)->whereNull('deleted_at')],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'estimate_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'label_ids' => ['sometimes', 'array', 'max:30'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')->where('workspace_id', $workspace->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignee_id.exists' => __('The assignee must be a member of this workspace.'),
            'project_id.exists' => __('Choose a project from this workspace.'),
        ];
    }
}
