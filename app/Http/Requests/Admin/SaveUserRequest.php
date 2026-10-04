<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update a team member (admin only).
 */
class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $target */
        $target = $this->route('user');

        return $target === null
            ? Gate::allows('create', User::class)
            : Gate::allows('update', $target);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'department_ids' => array_values(array_filter((array) $this->input('department_ids', []))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User|null $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique(User::class)->ignore($target?->getKey()),
            ],
            'title' => ['nullable', 'string', 'max:120'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'primary_department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'department_ids' => ['array'],
            'department_ids.*' => ['integer', Rule::exists('departments', 'id')],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $role = (string) $this->input('role');
                if ($role !== '' && Gate::denies('assignRole', [User::class, $role])) {
                    $validator->errors()->add('role', __('You cannot assign this role.'));
                }
            },
        ];
    }
}
