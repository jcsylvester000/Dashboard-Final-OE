<?php

namespace App\Domain\Onboarding;

use App\Domain\Identity\Permissions;
use App\Domain\Privacy\PrivacyProgram;
use App\Models\ActivityLog;
use App\Models\Annotation;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RecordLink;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * E-59: a short first-run checklist that fits the person's job. Steps tick themselves
 * off from what the person has actually done; it shows on the dashboard until they hide
 * it, and can be reopened from the user menu (Getting started).
 */
class GettingStarted
{
    /**
     * @return list<array{key: string, label: string, hint: string, href: string, done: bool}>
     */
    public function steps(User $user): array
    {
        $dept = $user->primary_department_id !== null ? (string) Department::query()->whereKey($user->primary_department_id)->value('slug') : '';
        $leads = DB::table('workspace_user')->where('user_id', $user->id)->whereIn('role', [Workspace::ROLE_OWNER, Workspace::ROLE_LEAD])->exists();
        $reviews = $leads || DB::table('task_reviewers')->where('user_id', $user->id)->exists();

        $steps = [
            $this->step('log_time', 'Log time on a task', 'Start the timer on a task or add time by hand.', route('time.timesheet', [], false),
                TimeEntry::query()->where('user_id', $user->id)->exists()),
            $this->step('comment', 'Comment on a task', 'Try an @mention so a teammate gets an alert.', route('tasks.mine', [], false),
                Comment::query()->where('user_id', $user->id)->exists()),
        ];
        if ($dept === 'dev') {
            $steps[] = $this->step('link_work', 'Link related work', 'Link a task to the one it blocks, duplicates or relates to.', route('tasks.mine', [], false),
                RecordLink::query()->where('created_by', $user->id)->exists());
        } else {
            $steps[] = $this->step('upload_file', 'Upload a file to a task', 'Drop a design, draft or screenshot on a task (new versions stack up).', route('tasks.mine', [], false),
                Attachment::query()->where('uploaded_by', $user->id)->exists());
            $steps[] = $this->step('reply_pin', 'Reply to a pin on an image', 'Open an image, read the pinned comments and answer one.', route('tasks.mine', [], false),
                Annotation::query()->where('user_id', $user->id)->exists());
        }
        if ($reviews) {
            $steps[] = $this->step('review', 'Approve or send back work', 'Open the Reviews queue and decide on one item.', route('reviews.index', [], false),
                ActivityLog::query()->where('actor_id', $user->id)->whereIn('action', ['task.approved', 'task.changes-requested'])->exists());
        }
        if ($user->can(Permissions::BILLING_VIEW)) {
            $steps[] = $this->step('build_draft', 'Build an invoice draft', 'Pick a client, open its billing period and build the draft.', route('billing.clients.index', [], false),
                Invoice::query()->where('created_by', $user->id)->exists());
            $steps[] = $this->step('record_payment', 'Record a payment', 'Open an unpaid invoice and record what came in.', route('billing.invoices.index', ['status' => 'open'], false),
                Payment::query()->where('recorded_by', $user->id)->exists());
        }
        if ($user->can(Permissions::USERS_MANAGE)) {
            $steps[] = $this->step('add_person', 'Add a team member', 'Create their account and send the setup link yourself.', route('admin.users.create', [], false),
                ActivityLog::query()->where('actor_id', $user->id)->where('action', 'user.created')->exists());
            $steps[] = $this->step('privacy_notice', 'Publish the staff privacy notice', 'Everyone acknowledges it once (Data Privacy Act).', route('admin.privacy.index', [], false),
                app(PrivacyProgram::class)->get()['version'] > 0);
        }

        return $steps;
    }

    /**
     * What the dashboard shows: null once hidden (or all done and hidden).
     *
     * @return array{steps: list<array{key: string, label: string, hint: string, href: string, done: bool}>, done: int, total: int}|null
     */
    public function card(User $user): ?array
    {
        if (self::hidden($user)) {
            return null;
        }
        $steps = $this->steps($user);
        $done = count(array_filter($steps, fn (array $s) => $s['done']));

        return ['steps' => $steps, 'done' => $done, 'total' => count($steps)];
    }

    public static function hidden(User $user): bool
    {
        return is_array($user->onboarding) && ($user->onboarding['hidden_at'] ?? null) !== null;
    }

    public function hide(User $user): void
    {
        $user->forceFill(['onboarding' => [...(is_array($user->onboarding) ? $user->onboarding : []), 'hidden_at' => now()->toIso8601String()]])->save();
    }

    public function reopen(User $user): void
    {
        $state = is_array($user->onboarding) ? $user->onboarding : [];
        unset($state['hidden_at']);
        $user->forceFill(['onboarding' => $state === [] ? null : $state])->save();
    }

    /**
     * @return array{key: string, label: string, hint: string, href: string, done: bool}
     */
    private function step(string $key, string $label, string $hint, string $href, bool $done): array
    {
        return compact('key', 'label', 'hint', 'href', 'done');
    }
}
