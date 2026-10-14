<?php

namespace App\Http\Controllers\Work;

use App\Domain\Files\AttachmentService;
use App\Domain\Work\ReviewService;
use App\Domain\Work\TaskPresenter;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * E-32: one queue of everything waiting for my decision across clients: tasks In Review
 * where I'm a named reviewer who hasn't approved yet, or (no reviewers named) where I may
 * review. Oldest request first, with the latest file for a quick look.
 */
class ReviewQueueController extends Controller
{
    public function __invoke(Request $request, TaskPresenter $present, ReviewService $reviews, AttachmentService $files): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceIds = Workspace::query()->visibleTo($user)->pluck('id');

        $tasks = Task::query()
            ->with([...TaskPresenter::WITH, 'reviewers'])
            ->whereIn('workspace_id', $workspaceIds)
            ->where('status_id', TaskStatus::idFor('in-review'))
            ->orderByRaw('review_requested_at is null')
            ->orderBy('review_requested_at')
            ->limit(300)
            ->get()
            ->filter(function (Task $t) use ($user) {
                $mine = $t->reviewers->firstWhere('user_id', $user->id);
                if ($t->reviewers->isNotEmpty()) {
                    return $mine !== null && $mine->decision !== 'approved';
                }

                return Gate::forUser($user)->allows('review', $t);
            })
            ->take(100)
            ->values();

        $previews = Attachment::query()
            ->where('attachable_type', (new Task)->getMorphClass())
            ->whereIn('attachable_id', $tasks->pluck('id'))
            ->where('is_latest', true)
            ->latest('id')
            ->get()
            ->unique('attachable_id')
            ->keyBy('attachable_id');
        $open = $present->openDependencyMap($tasks->pluck('id')->all());

        return Inertia::render('reviews/Index', [
            'items' => $tasks->map(function (Task $t) use ($present, $open, $previews, $files, $reviews) {
                $file = $previews->get($t->id);

                return [
                    ...$present->row($t, $open),
                    'review_requested_at' => $t->review_requested_at?->toIso8601String(),
                    'progress' => $t->reviewers->isNotEmpty() ? $reviews->progress($t) : null,
                    'file' => $file instanceof Attachment ? [
                        'name' => $file->original_name,
                        'version' => $file->version,
                        'image' => $file->isImage(),
                        'url' => $files->url($file),
                        'thumb_url' => $file->thumbnail_key !== null ? $files->url($file, thumbnail: true) : null,
                    ] : null,
                ];
            })->values(),
        ]);
    }
}
