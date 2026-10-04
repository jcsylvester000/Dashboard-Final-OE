<?php

namespace App\Http\Requests\Workspaces;

use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create / update a project. People must be workspace members and labels
 * must belong to the same workspace.
 */
class SaveProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return Gate::allows('contribute', $workspace);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'member_ids' => array_values(array_filter((array) $this->input('member_ids', []))),
            'label_ids' => array_values(array_filter((array) $this->input('label_ids', []))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        $member = Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id);

        return [
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(array_keys(Project::TYPES))],
            'status' => ['required', Rule::in(array_keys(Project::STATUSES))],
            'lead_user_id' => ['nullable', 'integer', $member],
            'description' => ['nullable', 'string', 'max:10000'],
            'start_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', Rule::when($this->filled('start_on'), ['after_or_equal:start_on'])],
            'member_ids' => ['array', 'max:100'],
            'member_ids.*' => ['integer', $member],
            'label_ids' => ['array', 'max:30'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')->where('workspace_id', $workspace->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lead_user_id.exists' => __('The lead must be a member of this workspace.'),
            'member_ids.*.exists' => __('Every project member must belong to this workspace.'),
            'label_ids.*.exists' => __('Labels must come from this workspace.'),
        ];
    }
}
