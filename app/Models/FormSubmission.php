<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-53 / E-54 / E-42: one filled-in form, tied to the version it was made with.
 *
 * @property int $id
 * @property int $form_id
 * @property int $form_version_id
 * @property int $workspace_id
 * @property string $reference
 * @property string|null $idempotency_key
 * @property int|null $submitted_by
 * @property string|null $submitter_name
 * @property array<string, mixed> $answers
 * @property array<string, list<int>>|null $files
 * @property int|null $task_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['form_id', 'form_version_id', 'workspace_id', 'reference', 'idempotency_key', 'submitted_by', 'submitter_name', 'answers', 'files', 'task_id'])]
class FormSubmission extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['answers' => 'array', 'files' => 'array'];
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
