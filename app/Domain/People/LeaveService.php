<?php

namespace App\Domain\People;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Identity\Permissions;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Leave requests (B-14): a person files, their department lead approves or rejects
 * (an admin does it when there is no lead or the lead is the one asking).
 * Approved leave reduces capacity everywhere (workload, people report, timesheet target).
 */
class LeaveService
{
    public function __construct(
        private readonly Capacity $capacity,
        private readonly Notifier $notifier,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array{type: string, starts_on: string, ends_on: string, reason?: string|null}  $data
     */
    public function request(User $user, array $data): LeaveRequest
    {
        $days = count($this->capacity->load($data['starts_on'], $data['ends_on'])->workingDays($data['starts_on'], $data['ends_on']));
        if ($days === 0) {
            throw ValidationException::withMessages(['ends_on' => __('Those dates are all weekends or holidays, so no leave is needed.')]);
        }
        $overlap = LeaveRequest::query()->where('user_id', $user->id)->whereIn('status', [LeaveRequest::PENDING, LeaveRequest::APPROVED])
            ->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => __('You already have leave filed for some of these days.')]);
        }

        $leave = LeaveRequest::create([...$data, 'user_id' => $user->id, 'working_days' => $days]);
        $this->activity->log('leave.requested', $leave, ['days' => $days], $user);

        $this->notifier->sendPersonal($this->approverIds($user), NotificationKind::Leave, [
            'title' => __(':name asked for :n day(s) off', ['name' => $user->name, 'n' => $days]),
            'body' => sprintf('%s · %s – %s', LeaveRequest::TYPES[$data['type']] ?? $data['type'], $data['starts_on'], $data['ends_on']),
            'url' => route('leave.index', [], false),
        ], $user);

        return $leave;
    }

    public function decide(LeaveRequest $leave, User $approver, bool $approve, ?string $note = null): LeaveRequest
    {
        if ($leave->status !== LeaveRequest::PENDING) {
            throw ValidationException::withMessages(['leave' => __('This request was already decided.')]);
        }

        $leave->forceFill([
            'status' => $approve ? LeaveRequest::APPROVED : LeaveRequest::REJECTED,
            'reviewed_by' => $approver->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();
        $this->activity->log($approve ? 'leave.approved' : 'leave.rejected', $leave, ['note' => $note], $approver);

        $this->notifier->sendPersonal([$leave->user_id], NotificationKind::Leave, [
            'title' => $approve ? __(':name approved your leave', ['name' => $approver->name]) : __(':name declined your leave', ['name' => $approver->name]),
            'body' => sprintf('%s – %s%s', $leave->starts_on->toDateString(), $leave->ends_on->toDateString(), $note ? ' · '.$note : ''),
            'url' => route('leave.index', [], false),
        ], $approver);

        return $leave;
    }

    public function cancel(LeaveRequest $leave, User $by): void
    {
        if (! in_array($leave->status, [LeaveRequest::PENDING, LeaveRequest::APPROVED], true) || $leave->ends_on->toDateString() < now()->toDateString()) {
            throw ValidationException::withMessages(['leave' => __('Only upcoming leave can be cancelled.')]);
        }
        $leave->forceFill(['status' => LeaveRequest::CANCELLED])->save();
        $this->activity->log('leave.cancelled', $leave, [], $by);
    }

    /** The department lead approves; admins (users.manage) cover leads and people with no lead. */
    public function canDecide(User $approver, LeaveRequest $leave): bool
    {
        if ($approver->id === $leave->user_id) {
            return false;
        }
        if ($approver->can(Permissions::USERS_MANAGE)) {
            return true;
        }
        $deptId = User::query()->whereKey($leave->user_id)->value('primary_department_id');

        return $deptId !== null && (int) Department::query()->whereKey($deptId)->value('lead_user_id') === $approver->id;
    }

    /**
     * @return list<int>
     */
    public function approverIds(User $user): array
    {
        $lead = $user->primary_department_id !== null
            ? Department::query()->whereKey($user->primary_department_id)->value('lead_user_id')
            : null;
        if ($lead !== null && (int) $lead !== $user->id) {
            return [(int) $lead];
        }

        return User::permission(Permissions::USERS_MANAGE)->where('is_active', true)->whereKeyNot($user->id)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Requests this person may decide (pending), oldest first.
     *
     * @return Collection<int, LeaveRequest>
     */
    public function toDecide(User $approver): Collection
    {
        $query = LeaveRequest::query()->with('user:id,name,primary_department_id')->where('status', LeaveRequest::PENDING)
            ->where('user_id', '!=', $approver->id);
        if (! $approver->can(Permissions::USERS_MANAGE)) {
            $depts = Department::query()->where('lead_user_id', $approver->id)->pluck('id');
            $query->whereHas('user', fn ($q) => $q->whereIn('primary_department_id', $depts));
        }

        return $query->orderBy('starts_on')->get();
    }
}
