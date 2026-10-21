<?php

namespace App\Domain\Forms;

use App\Domain\Files\AttachmentService;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Domain\Work\TaskService;
use App\Models\Department;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * E-54: what happens when a form is submitted. Answers are validated against the
 * published version; the output then creates one task (title from a pattern, mapped
 * fields, answers in the description, files attached) or keeps the answers as a record.
 * A retried submit with the same idempotency key returns the first submission, so a
 * submission never creates two tasks. The right people are alerted (E-42).
 */
class FormSubmitter
{
    public const OUTPUT_TYPES = ['task' => 'Create a task', 'record' => 'Keep as a record'];

    public function __construct(
        private readonly TaskService $tasks,
        private readonly AttachmentService $files,
        private readonly Notifier $notifier,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Clean an output mapping from the builder.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $schema  normalized
     * @return array<string, mixed>
     */
    public static function normalizeOutput(array $raw, array $schema, Workspace $workspace): array
    {
        $fields = collect(FormSchema::fields($schema))->keyBy('key');
        $map = [];
        foreach ((array) ($raw['map'] ?? []) as $target => $key) {
            $target = (string) $target;
            if (! is_string($key) || ! $fields->has($key)) {
                continue;
            }
            $ok = $target === 'due_on' || $target === 'priority'
                || (str_starts_with($target, 'custom.') && collect($workspace->custom_fields ?? [])->contains('key', substr($target, 7)))
                || (str_starts_with($target, 'work.') && in_array(substr($target, 5), ['channel', 'campaign', 'deliverable_type', 'target_url', 'keyword', 'work_type', 'question', 'method', 'sources', 'confidence', 'decision'], true));
            if ($ok) {
                $map[$target] = $key;
            }
        }
        $department = isset($raw['department_id']) && $raw['department_id'] !== '' ? (int) $raw['department_id'] : null;

        return [
            'type' => array_key_exists((string) ($raw['type'] ?? 'task'), self::OUTPUT_TYPES) ? (string) ($raw['type'] ?? 'task') : 'task',
            'title' => mb_substr(trim((string) ($raw['title'] ?? '')) ?: 'Request: {{reference}}', 0, 200),
            'department_id' => $department !== null && Department::query()->whereKey($department)->exists() ? $department : null,
            'priority' => in_array($raw['priority'] ?? null, ['low', 'normal', 'high', 'urgent'], true) ? $raw['priority'] : 'normal',
            'due_in_days' => isset($raw['due_in_days']) && $raw['due_in_days'] !== '' ? max(0, min(365, (int) $raw['due_in_days'])) : null,
            'map' => $map,
            'notify_user_ids' => array_values(array_unique(array_map('intval', (array) ($raw['notify_user_ids'] ?? [])))),
        ];
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, list<UploadedFile>>  $uploads  field key => files
     */
    public function submit(Form $form, array $answers, array $uploads, ?User $by, ?string $submitterName, ?string $idempotencyKey): FormSubmission
    {
        if ($idempotencyKey !== null && ($existing = FormSubmission::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing; // a retried submit
        }
        $version = $form->current_version_id !== null ? FormVersion::query()->find($form->current_version_id) : null;
        if ($version === null || $form->archived) {
            throw ValidationException::withMessages(['form' => __('This form is not open for submissions.')]);
        }
        $schema = $version->schema;
        $clean = FormSchema::validate($schema, $answers, array_map(fn ($list) => count($list), $uploads));
        $visibleFiles = collect(FormSchema::visibleFields($schema, $answers))->where('type', 'file')->pluck('key')->all();
        $this->validateFiles($uploads, $visibleFiles);
        $workspace = Workspace::query()->findOrFail($form->workspace_id);
        $actor = $by ?? $this->actorFor($form, $workspace);

        try {
            $submission = DB::transaction(function () use ($form, $version, $workspace, $clean, $uploads, $visibleFiles, $by, $actor, $submitterName, $idempotencyKey, $schema) {
                $submission = FormSubmission::query()->create([
                    'form_id' => $form->id,
                    'form_version_id' => $version->id,
                    'workspace_id' => $workspace->id,
                    'reference' => 'REQ-'.strtoupper(Str::random(6)),
                    'idempotency_key' => $idempotencyKey,
                    'submitted_by' => $by?->id,
                    'submitter_name' => $submitterName !== null ? mb_substr(trim($submitterName), 0, 120) : null,
                    'answers' => $clean,
                ]);
                $output = $version->output;
                $target = $workspace;
                if (($output['type'] ?? 'task') === 'task') {
                    $task = $this->tasks->create($workspace, $this->taskData($output, $schema, $clean, $submission, $workspace), $actor);
                    $submission->forceFill(['task_id' => $task->id])->save();
                    $target = $task;
                }
                $saved = [];
                foreach ($uploads as $key => $list) {
                    if (! in_array($key, $visibleFiles, true)) {
                        continue;
                    }
                    foreach ($list as $file) {
                        $saved[$key][] = $this->files->attach($target, $workspace->id, $file, $actor)->id;
                    }
                }
                if ($saved !== []) {
                    $submission->forceFill(['files' => $saved])->save();
                }

                return $submission;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two copies of the same submit at once: the first one wins.
            $existing = $idempotencyKey !== null ? FormSubmission::query()->where('idempotency_key', $idempotencyKey)->first() : null;
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        $this->activity->log('form.submitted', $submission->task_id !== null ? Task::query()->find($submission->task_id) : $workspace, [
            'form' => $form->name, 'reference' => $submission->reference, 'public' => $by === null,
        ], $by);
        $this->alert($form, $workspace, $submission, $by);

        return $submission;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function taskData(array $output, array $schema, array $answers, FormSubmission $submission, Workspace $workspace): array
    {
        $map = (array) ($output['map'] ?? []);
        $title = preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/', function ($m) use ($answers, $submission) {
            if ($m[1] === 'reference') {
                return $submission->reference;
            }
            $v = $answers[$m[1]] ?? '';

            return is_array($v) ? implode(', ', $v) : (string) $v;
        }, (string) ($output['title'] ?? 'Request: {{reference}}')) ?? 'Request';
        $data = [
            'title' => mb_substr(trim($title) !== '' ? trim($title) : 'Request '.$submission->reference, 0, 255),
            'description' => $this->summary($schema, $answers, $submission),
            'department_id' => $output['department_id'] ?? null,
            'priority' => $output['priority'] ?? 'normal',
            'due_on' => isset($output['due_in_days']) ? now()->addDays((int) $output['due_in_days'])->toDateString() : null,
        ];
        $custom = [];
        $work = [];
        foreach ($map as $target => $key) {
            $value = $answers[$key] ?? null;
            if ($value === null) {
                continue;
            }
            $flat = is_array($value) ? implode(', ', $value) : $value;
            match (true) {
                $target === 'due_on' && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 => $data['due_on'] = $value,
                $target === 'priority' && in_array(strtolower((string) $flat), ['low', 'normal', 'high', 'urgent'], true) => $data['priority'] = strtolower((string) $flat),
                str_starts_with((string) $target, 'custom.') => $custom[substr((string) $target, 7)] = $flat,
                str_starts_with((string) $target, 'work.') => $work[substr((string) $target, 5)] = (string) $flat,
                default => null,
            };
        }
        if ($custom !== []) {
            $data['custom_values'] = $custom;
        }
        if ($work !== []) {
            $data['work_details'] = $work;
        }

        return $data;
    }

    /**
     * All answers as Markdown, in form order (labels from the version used).
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $answers
     */
    private function summary(array $schema, array $answers, FormSubmission $submission): string
    {
        $lines = ['From form submission **'.$submission->reference.'**'.($submission->submitter_name !== null ? ' by '.$submission->submitter_name : '').'.', ''];
        foreach (FormSchema::visibleFields($schema, $answers) as $f) {
            if (! array_key_exists((string) $f['key'], $answers)) {
                continue;
            }
            $v = $answers[$f['key']];
            $lines[] = '**'.$f['label'].':** '.(is_array($v) ? implode(', ', $v) : (string) $v);
        }

        return mb_substr(implode("\n", $lines), 0, 20000);
    }

    /**
     * @param  array<string, list<UploadedFile>>  $uploads
     * @param  list<string>  $visible
     */
    private function validateFiles(array $uploads, array $visible): void
    {
        $data = [];
        $rules = [];
        foreach ($uploads as $key => $list) {
            if (in_array($key, $visible, true)) {
                $data[$key] = $list;
                $rules["{$key}.*"] = $this->files->rules();
            }
        }
        if ($rules !== []) {
            Validator::make($data, $rules, [
                '*.mimes' => __('That file type is not allowed. Allowed: :types.', ['types' => implode(', ', $this->files->allowedExtensions())]),
                '*.max' => __('Files can be at most :mb MB.', ['mb' => $this->files->maxMb()]),
            ])->validate();
        }
    }

    /** Public submissions are recorded as the form's owner, else a lead of the client. */
    private function actorFor(Form $form, Workspace $workspace): User
    {
        $owner = $form->created_by !== null ? User::query()->active()->find($form->created_by) : null;
        $lead = $owner ?? $workspace->members()->wherePivotIn('role', [Workspace::ROLE_LEAD, Workspace::ROLE_OWNER])->first();
        if (! $lead instanceof User) {
            throw ValidationException::withMessages(['form' => __('This form has no owner to receive requests. Ask the agency to check it.')]);
        }

        return $lead;
    }

    private function alert(Form $form, Workspace $workspace, FormSubmission $submission, ?User $by): void
    {
        $ids = (array) ($form->currentVersion?->output['notify_user_ids'] ?? []);
        if ($ids === []) {
            $ids = $workspace->members()->wherePivotIn('role', [Workspace::ROLE_LEAD, Workspace::ROLE_OWNER])->pluck('users.id')->all();
        }
        $task = $submission->task_id !== null ? Task::query()->find($submission->task_id) : null;
        $this->notifier->send(array_map('intval', $ids), NotificationKind::Intake, $workspace, [
            'title' => __('New :form submission (:ref)', ['form' => $form->name, 'ref' => $submission->reference]),
            'body' => $task !== null ? $task->title : ($submission->submitter_name ?? ''),
            'url' => $task !== null ? $this->notifier->taskUrl($task, $workspace) : route('workspaces.forms.submissions', [$workspace, $form], false),
            'task_id' => $task?->id,
        ], $by);
    }
}
