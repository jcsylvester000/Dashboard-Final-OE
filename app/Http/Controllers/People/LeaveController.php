<?php

namespace App\Http\Controllers\People;

use App\Domain\People\Capacity;
use App\Domain\People\LeaveService;
use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My leave, leave to approve, and upcoming holidays (B-14).
 */
class LeaveController extends Controller
{
    public function __construct(private readonly LeaveService $leave) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $row = fn (LeaveRequest $l): array => [
            'id' => $l->id,
            'person' => $l->relationLoaded('user') ? $l->user->name : null,
            'type' => $l->type,
            'starts_on' => $l->starts_on->toDateString(),
            'ends_on' => $l->ends_on->toDateString(),
            'working_days' => $l->working_days,
            'reason' => $l->reason,
            'status' => $l->status,
            'reviewer' => $l->relationLoaded('reviewer') ? $l->reviewer?->name : null,
            'review_note' => $l->review_note,
            'can_cancel' => in_array($l->status, [LeaveRequest::PENDING, LeaveRequest::APPROVED], true) && $l->ends_on->toDateString() >= now()->toDateString(),
        ];

        $year = now()->year;

        return Inertia::render('people/Leave', [
            'mine' => LeaveRequest::query()->with('reviewer:id,name')->where('user_id', $user->id)->latest('starts_on')->limit(30)->get()->map($row)->values(),
            'toDecide' => $this->leave->toDecide($user)->map($row)->values(),
            'teamUpcoming' => $this->teamUpcoming($user)->map($row)->values(),
            'holidays' => Capacity::holidays("{$year}-01-01", ($year + 1).'-01-31'),
            'types' => LeaveRequest::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(LeaveRequest::TYPES))],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $leave = $this->leave->request($user, $data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('Leave filed for :n day. Your lead has been notified.|Leave filed for :n days. Your lead has been notified.', $leave->working_days, ['n' => $leave->working_days]),
        ]);

        return back();
    }

    public function decide(Request $request, LeaveRequest $leave): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->leave->canDecide($user, $leave), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:500'],
        ]);
        $this->leave->decide($leave, $user, $data['decision'] === 'approve', $data['note'] ?? null);

        return back();
    }

    public function cancel(Request $request, LeaveRequest $leave): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($leave->user_id === $user->id, 403);
        $this->leave->cancel($leave, $user);

        return back();
    }

    /**
     * Approved leave in the next 30 days for the viewer's department (who's out).
     *
     * @return Collection<int, LeaveRequest>
     */
    private function teamUpcoming(User $user): Collection
    {
        if ($user->primary_department_id === null) {
            return collect();
        }

        return LeaveRequest::query()->with('user:id,name')
            ->where('status', LeaveRequest::APPROVED)
            ->whereDate('ends_on', '>=', now()->toDateString())
            ->whereDate('starts_on', '<=', now()->addDays(30)->toDateString())
            ->whereHas('user', fn ($q) => $q->where('primary_department_id', $user->primary_department_id))
            ->orderBy('starts_on')->get()->toBase();
    }
}
