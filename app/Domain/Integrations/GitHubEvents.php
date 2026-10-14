<?php

namespace App\Domain\Integrations;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Work\TaskService;
use App\Models\CodeRepository;
use App\Models\Task;
use App\Models\TaskCodeLink;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * E-24 (D-04): turn GitHub webhook events into task links and (optionally) status moves.
 * A task is named by its key (BRW-24) in a PR title, a branch name or a commit message.
 * Status moves go through TaskService, so every guard (review sign-off, required
 * checklist lines) still applies; a blocked move is reported, never forced.
 */
class GitHubEvents
{
    private const MAX_COMMITS = 20;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly ActivityLogger $activity,
    ) {}

    /** Constant-time check of X-Hub-Signature-256 ("sha256=<hex hmac of the raw body>"). */
    public static function validSignature(string $body, ?string $header, string $secret): bool
    {
        if ($secret === '' || $header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $header);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, result: string}
     */
    public function handle(string $event, array $payload): array
    {
        if ($event === 'ping') {
            return ['status' => 'done', 'result' => 'pong'];
        }
        $full = strtolower((string) data_get($payload, 'repository.full_name', ''));
        $repo = $full !== '' ? CodeRepository::query()->where('full_name', $full)->first() : null;
        if ($repo === null) {
            return ['status' => 'ignored', 'result' => 'Repository not linked to a client.'];
        }
        $workspace = Workspace::query()->find($repo->workspace_id);
        if ($workspace === null || $workspace->key === null) {
            return ['status' => 'ignored', 'result' => 'Client has no task key.'];
        }

        return match ($event) {
            'pull_request' => $this->pullRequest($repo, $workspace, $payload),
            'check_suite' => $this->checks((string) data_get($payload, 'check_suite.head_sha', ''), (string) (data_get($payload, 'check_suite.conclusion') ?? data_get($payload, 'check_suite.status', ''))),
            'status' => $this->checks((string) data_get($payload, 'sha', ''), (string) data_get($payload, 'state', '')),
            'push' => $this->push($repo, $workspace, $payload),
            default => ['status' => 'ignored', 'result' => "Event {$event} is not used."],
        };
    }

    /**
     * Task numbers named with this client's key, e.g. "BRW-24 Fix cart" -> [24].
     *
     * @return list<int>
     */
    public static function taskNumbers(string $key, string $text): array
    {
        preg_match_all('/(?<![A-Za-z0-9])'.preg_quote($key, '/').'-(\d{1,7})(?!\d)/i', $text, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, result: string}
     */
    private function pullRequest(CodeRepository $repo, Workspace $workspace, array $payload): array
    {
        $action = (string) data_get($payload, 'action', '');
        $pr = (array) data_get($payload, 'pull_request', []);
        $number = (int) ($pr['number'] ?? 0);
        $title = (string) ($pr['title'] ?? '');
        $branch = (string) data_get($pr, 'head.ref', '');
        $tasks = $this->tasksNamed($workspace, $title.' '.$branch);
        if ($tasks === [] || $number === 0) {
            return ['status' => 'ignored', 'result' => 'No task key in the PR title or branch.'];
        }
        $merged = (bool) ($pr['merged'] ?? false);
        $state = $merged ? 'merged' : (($pr['state'] ?? 'open') === 'closed' ? 'closed' : (($pr['draft'] ?? false) ? 'draft' : 'open'));
        $reviewers = array_values(array_filter(array_map(fn ($r) => is_array($r) ? (string) ($r['login'] ?? '') : '', (array) ($pr['requested_reviewers'] ?? []))));

        $notes = [];
        foreach ($tasks as $task) {
            TaskCodeLink::query()->updateOrCreate(
                ['task_id' => $task->id, 'repository' => $repo->full_name, 'kind' => 'pr', 'ref' => '#'.$number],
                [
                    'number' => $number, 'title' => mb_substr($title, 0, 300), 'url' => (string) ($pr['html_url'] ?? ''),
                    'state' => $state, 'head_sha' => (string) data_get($pr, 'head.sha', '') ?: null,
                    'reviewers' => $reviewers, 'author' => (string) data_get($pr, 'user.login', '') ?: null,
                ],
            );
            if ($branch !== '') {
                $this->link($task, $repo, 'branch', $branch, $branch, 'https://github.com/'.$repo->full_name.'/tree/'.rawurlencode($branch));
            }

            $target = match (true) {
                in_array($action, ['opened', 'reopened', 'ready_for_review'], true) && $state === 'open' => $repo->on_open_status,
                $action === 'closed' && $merged => $repo->on_merge_status,
                default => null,
            };
            if ($target !== null) {
                $notes[] = $this->move($task, $repo, $target, (string) ($pr['html_url'] ?? ''));
            }
        }

        return ['status' => 'done', 'result' => mb_substr('PR #'.$number.' linked to '.count($tasks).' task(s). '.implode(' ', array_filter($notes)), 0, 480)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, result: string}
     */
    private function push(CodeRepository $repo, Workspace $workspace, array $payload): array
    {
        $ref = (string) data_get($payload, 'ref', '');
        $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : '';
        $linked = 0;
        if ($branch !== '') {
            foreach ($this->tasksNamed($workspace, $branch) as $task) {
                $this->link($task, $repo, 'branch', $branch, $branch, 'https://github.com/'.$repo->full_name.'/tree/'.rawurlencode($branch));
                $linked++;
            }
        }
        foreach (array_slice((array) data_get($payload, 'commits', []), 0, self::MAX_COMMITS) as $commit) {
            if (! is_array($commit)) {
                continue;
            }
            $message = strtok((string) ($commit['message'] ?? ''), "\n") ?: '';
            $sha = (string) ($commit['id'] ?? '');
            foreach ($this->tasksNamed($workspace, $message) as $task) {
                $this->link($task, $repo, 'commit', substr($sha, 0, 12), $message, (string) ($commit['url'] ?? ''), $sha);
                $linked++;
            }
        }

        return $linked > 0 ? ['status' => 'done', 'result' => "{$linked} link(s) recorded."] : ['status' => 'ignored', 'result' => 'No task key in the branch or commits.'];
    }

    /**
     * @return array{status: string, result: string}
     */
    private function checks(string $sha, string $outcome): array
    {
        if ($sha === '') {
            return ['status' => 'ignored', 'result' => 'No commit in the event.'];
        }
        $checks = match ($outcome) {
            'success', 'neutral', 'skipped' => 'success',
            'failure', 'error', 'timed_out', 'cancelled', 'action_required', 'startup_failure' => 'failure',
            default => 'pending',
        };
        $count = TaskCodeLink::query()->where('kind', 'pr')->where('head_sha', $sha)->update(['checks' => $checks, 'updated_at' => now()]);

        return $count > 0 ? ['status' => 'done', 'result' => "Checks {$checks} on {$count} PR link(s)."] : ['status' => 'ignored', 'result' => 'No linked PR for this commit.'];
    }

    /**
     * @return list<Task>
     */
    private function tasksNamed(Workspace $workspace, string $text): array
    {
        $numbers = self::taskNumbers((string) $workspace->key, $text);
        if ($numbers === []) {
            return [];
        }

        return Task::query()->with('workspace:id,key')->where('workspace_id', $workspace->id)->whereIn('number', $numbers)->get()->all();
    }

    private function link(Task $task, CodeRepository $repo, string $kind, string $ref, string $title, string $url, ?string $sha = null): void
    {
        TaskCodeLink::query()->updateOrCreate(
            ['task_id' => $task->id, 'repository' => $repo->full_name, 'kind' => $kind, 'ref' => mb_substr($ref, 0, 255)],
            ['title' => mb_substr($title, 0, 300), 'url' => $url, 'head_sha' => $sha],
        );
    }

    /** Move forward only, through every guard; returns a note when the move is blocked or skipped. */
    private function move(Task $task, CodeRepository $repo, string $slug, string $prUrl): ?string
    {
        $target = TaskStatus::ordered()->firstWhere('slug', $slug);
        $current = TaskStatus::ordered()->firstWhere('id', $task->status_id);
        if ($target === null || $current === null || $current->position >= $target->position || $current->category === TaskStatus::CATEGORY_DONE) {
            return null; // already there or further along
        }
        $actor = $repo->created_by !== null ? User::query()->find($repo->created_by) : null;
        if ($actor === null) {
            return 'No user to move tasks as (re-add the repository).';
        }
        try {
            $this->tasks->update($task, ['status_id' => $target->id], $actor);
            $this->activity->log('task.moved-by-github', $task, ['to' => $slug, 'pr' => $prUrl], $actor);

            return null;
        } catch (ValidationException $e) {
            $reason = collect($e->errors())->flatten()->first();

            return $task->keyLabel().' not moved: '.(is_string($reason) ? $reason : 'blocked').'.';
        }
    }
}
