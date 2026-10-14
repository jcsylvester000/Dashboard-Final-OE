<?php

namespace App\Domain\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Data Privacy Act (RA 10173) requests for a team member (brutal scenario H5):
 * - export(): everything the app holds about the person, as JSON (right to access / portability).
 * - anonymise(): erase identity for a deactivated person while keeping work records
 *   (time, approvals, invoices) that the agency must retain for billing and tax.
 * Not legal advice: confirm retention periods with counsel.
 */
class PersonalData
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @return array<string, mixed>
     */
    public function export(User $user, User $by): array
    {
        $user->loadMissing(['primaryDepartment:id,name', 'departments:id,name']);
        $taskCols = ['tasks.id', 'tasks.title', 'tasks.created_at', 'tasks.completed_at', 'workspaces.name as client'];

        $data = [
            'generated_at' => now()->toIso8601String(),
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'title' => $user->title,
                'role' => $user->getRoleNames()->first(),
                'primary_department' => $user->primaryDepartment?->name,
                'departments' => $user->departments->pluck('name')->values(),
                'active' => $user->is_active,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'deactivated_at' => $user->deactivated_at?->toIso8601String(),
                'notification_preferences' => $user->notification_preferences,
            ],
            'client_memberships' => DB::table('workspace_user')->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
                ->where('workspace_user.user_id', $user->id)->get(['workspaces.name as client', 'workspace_user.role', 'workspace_user.created_at as since']),
            'tasks_assigned' => DB::table('tasks')->join('workspaces', 'workspaces.id', '=', 'tasks.workspace_id')
                ->where('tasks.assignee_id', $user->id)->whereNull('tasks.deleted_at')->orderBy('tasks.id')->get($taskCols),
            'tasks_reported' => DB::table('tasks')->join('workspaces', 'workspaces.id', '=', 'tasks.workspace_id')
                ->where('tasks.reporter_id', $user->id)->whereNull('tasks.deleted_at')->orderBy('tasks.id')->get($taskCols),
            'time_entries' => DB::table('time_entries')->join('workspaces', 'workspaces.id', '=', 'time_entries.workspace_id')
                ->leftJoin('tasks', 'tasks.id', '=', 'time_entries.task_id')
                ->where('time_entries.user_id', $user->id)->orderBy('time_entries.entry_date')
                ->get(['time_entries.entry_date', 'time_entries.minutes', 'time_entries.note', 'time_entries.is_billable', 'time_entries.approved_at', 'workspaces.name as client', 'tasks.title as task']),
            'comments' => DB::table('comments')->where('user_id', $user->id)->whereNull('deleted_at')->orderBy('id')
                ->get(['id', 'commentable_type', 'commentable_id', 'body', 'created_at', 'edited_at']),
            'files' => DB::table('drive_files')->where('owner_id', $user->id)->orderBy('id')->get(['id', 'name', 'size', 'visibility', 'created_at']),
            'sign_in_and_activity' => DB::table('activity_log')->where('actor_id', $user->id)->latest('id')->limit(1000)
                ->get(['action', 'subject_type', 'subject_id', 'ip_address', 'user_agent', 'created_at']),
            'about_them' => DB::table('activity_log')->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->latest('id')->limit(500)
                ->get(['action', 'actor_id', 'properties', 'created_at']),
        ];

        $this->activity->log('user.data-exported', $user, [], $by);

        return $data;
    }

    /**
     * Replace the person's identity with a placeholder and delete what is only about them.
     * Kept (legitimate interest / legal retention): time entries, approvals, invoices,
     * tasks and comments (shown as "Former member #id"), files they uploaded for clients.
     *
     * @return array<string, int>
     */
    public function anonymise(User $user, User $by): array
    {
        if ($user->is_active) {
            throw ValidationException::withMessages(['user' => __('Deactivate the account before anonymising it.')]);
        }
        if ($user->is($by)) {
            throw ValidationException::withMessages(['user' => __('You cannot anonymise your own account.')]);
        }

        $removed = DB::transaction(function () use ($user) {
            $removed = [
                'sessions' => DB::table('sessions')->where('user_id', $user->id)->delete(),
                'api_tokens' => $user->tokens()->delete(),
                'access_links' => $user->accessLinks()->delete(),
                'notifications' => DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete(),
                'saved_views' => DB::table('saved_views')->where('user_id', $user->id)->delete(),
                'watching' => DB::table('task_watchers')->where('user_id', $user->id)->delete(),
                // IP address and browser are personal data; the actions themselves stay for the audit trail.
                'activity_rows_scrubbed' => DB::table('activity_log')->where('actor_id', $user->id)->update(['ip_address' => null, 'user_agent' => null]),
            ];

            $user->forceFill([
                'name' => 'Former member #'.$user->id,
                'email' => 'former-'.$user->id.'@anonymised.invalid',
                'title' => null,
                'password' => Str::password(40),
                'remember_token' => null,
                'notification_preferences' => null,
                'must_change_password' => true,
                'last_login_at' => null,
            ])->save();

            return $removed;
        });

        // No name or email in the audit row.
        $this->activity->log('user.anonymised', $user, ['removed' => $removed], $by);

        return $removed;
    }
}
