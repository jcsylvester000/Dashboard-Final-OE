<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\NotificationKind;
use App\Domain\Work\ReviewService;
use App\Models\InboxNotification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Needs my attention" inbox. Actionable alerts (assigned, mentioned, handoff,
 * overdue, escalation) stay here until marked done or snoozed; the rest clear once read.
 */
class InboxController extends Controller
{
    public const TABS = ['attention', 'snoozed', 'done', 'all'];

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $tab = $request->validate(['tab' => ['nullable', Rule::in(self::TABS)]])['tab'] ?? 'attention';

        $page = $this->tab($this->mine($user), $tab)
            ->with('workspace:id,name,slug')
            ->latest()
            ->paginate(30)
            ->withQueryString();

        // E-38: review alerts the person may approve right here.
        $quick = $this->quickApprovable($user, $page->getCollection()->all());

        return Inertia::render('inbox/Index', [
            'tab' => $tab,
            'counts' => collect(self::TABS)->mapWithKeys(fn (string $t) => [$t => $this->tab($this->mine($user), $t)->count()]),
            'items' => $page->through(fn (InboxNotification $n): array => [...$this->present($n), 'can_approve' => isset($quick[$n->id])]),
            'kinds' => collect(NotificationKind::cases())->mapWithKeys(fn (NotificationKind $k) => [$k->value => $k->label()]),
        ]);
    }

    /**
     * Mark read and go to the task / project the alert is about.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $item = $this->find($request, $notification);
        if ($item->read_at === null) {
            $item->forceFill(['read_at' => now()])->save();
        }

        $url = (string) ($item->data['url'] ?? '');

        // Only same-site relative paths (no open redirects). Browsers read "/\host" as "//host",
        // so backslashes and control characters are refused too.
        return str_starts_with($url, '/') && ! str_starts_with($url, '//')
            && ! str_contains($url, '\\') && preg_match('/[\x00-\x1F\x7F]/', $url) !== 1
            ? redirect($url)
            : to_route('inbox.index');
    }

    /**
     * E-38: approve the task straight from its review alert. Only when the reviewer opened the
     * task in the last 24 hours after its newest file; "Request changes" always opens the task.
     */
    public function approve(Request $request, string $notification, ReviewService $reviews): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $item = $this->find($request, $notification);
        $task = $item->kind === NotificationKind::Review->value && isset($item->data['task_id'])
            ? Task::query()->with('workspace')->find((int) $item->data['task_id']) : null;
        abort_if($task === null, 404);
        Gate::forUser($user)->authorize('review', $task);
        if (! $reviews->canQuickApprove($task, $user)) {
            throw ValidationException::withMessages(['approve' => __('Open the task and look at the latest version first, then approve.')]);
        }

        $message = DB::transaction(fn () => $reviews->decide($task, $user, 'approve', '', 'inbox'));
        $item->forceFill(['read_at' => $item->read_at ?? now(), 'done_at' => now()])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * Ids of the given alerts whose task this person can approve from the inbox.
     *
     * @param  array<int, InboxNotification>  $items
     * @return array<string, true>
     */
    private function quickApprovable(User $user, array $items): array
    {
        $reviews = app(ReviewService::class);
        $out = [];
        foreach ($items as $n) {
            if ($n->kind !== NotificationKind::Review->value || $n->done_at !== null || ! isset($n->data['task_id'])) {
                continue;
            }
            $task = Task::query()->with('workspace')->find((int) $n->data['task_id']);
            if ($task !== null && Gate::forUser($user)->allows('review', $task) && $reviews->canQuickApprove($task, $user)) {
                $out[$n->id] = true;
            }
        }

        return $out;
    }

    public function read(Request $request): RedirectResponse
    {
        $query = $this->selected($request);
        $query->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }

    public function done(Request $request): RedirectResponse
    {
        $now = now();
        $this->selected($request)->whereNull('done_at')->update(['done_at' => $now, 'read_at' => $now]);

        return back();
    }

    public function snooze(Request $request, string $notification): RedirectResponse
    {
        $item = $this->find($request, $notification);
        $until = $request->validate(['until' => ['required', Rule::in(['1h', 'tomorrow', 'next_week'])]])['until'];

        $item->forceFill([
            'snoozed_until' => match ($until) {
                '1h' => now()->addHour(),
                'tomorrow' => now()->addDay()->setTime(9, 0),
                default => now()->next('Monday')->setTime(9, 0),
            },
            'read_at' => $item->read_at ?? now(),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Snoozed.')]);

        return back();
    }

    /**
     * Back to "Needs my attention" (undo done / snooze).
     */
    public function restore(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->forceFill(['done_at' => null, 'snoozed_until' => null])->save();

        return back();
    }

    /**
     * @return Builder<InboxNotification>
     */
    private function mine(User $user): Builder
    {
        return InboxNotification::query()->forUser($user);
    }

    /**
     * @param  Builder<InboxNotification>  $query
     * @return Builder<InboxNotification>
     */
    private function tab(Builder $query, string $tab): Builder
    {
        $actionable = array_map(fn (NotificationKind $k) => $k->value, array_filter(NotificationKind::cases(), fn (NotificationKind $k) => $k->needsAction()));

        return match ($tab) {
            'attention' => $query->active()->where(fn (Builder $q) => $q->whereNull('read_at')->orWhereIn('kind', $actionable)),
            'snoozed' => $query->whereNull('done_at')->where('snoozed_until', '>', now()),
            'done' => $query->whereNotNull('done_at'),
            default => $query,
        };
    }

    private function find(Request $request, string $id): InboxNotification
    {
        /** @var User $user */
        $user = $request->user();

        return $this->mine($user)->whereKey($id)->firstOrFail();
    }

    /**
     * The request's `ids`, or every active alert when `all` is set.
     *
     * @return Builder<InboxNotification>
     */
    private function selected(Request $request): Builder
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'all' => ['sometimes', 'boolean'],
            'ids' => ['required_without:all', 'array', 'max:200'],
            'ids.*' => ['string', 'uuid'],
        ]);

        $query = $this->mine($user);

        return ($data['all'] ?? false)
            ? $query->active()
            : $query->whereKey($data['ids'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(InboxNotification $n): array
    {
        return [
            'id' => $n->id,
            'kind' => $n->kind,
            'title' => (string) ($n->data['title'] ?? ''),
            'body' => $n->data['body'] ?? null,
            'workspace' => $n->workspace->name ?? ($n->data['workspace'] ?? null),
            'actor' => $n->data['actor'] ?? null,
            'read' => $n->read_at !== null,
            'quiet' => $n->quiet,
            'done' => $n->done_at !== null,
            'snoozed_until' => $n->snoozed_until !== null && $n->snoozed_until->isFuture() ? $n->snoozed_until->toIso8601String() : null,
            'created_at' => $n->created_at->toIso8601String(),
        ];
    }
}
