<?php

namespace App\Domain\Workspaces;

use App\Events\Work\UserMentioned;
use App\Models\Contracts\Mentionable;
use App\Models\Mention;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @mentions use the token  @[Display Name](user:12)  inside plain text.
 * The editor inserts tokens; the server keeps only tokens for active members
 * of the record's workspace (others become plain "@Name") and stores one
 * mentions row per tagged person. Each new mention fires UserMentioned (P4 alerts).
 */
class MentionService
{
    public const TOKEN = '/@\[([^\]\r\n]{1,80})\]\(user:(\d{1,10})\)/u';

    /**
     * Strip tokens that point to people outside the workspace.
     *
     * @return array{text: string|null, user_ids: list<int>}
     */
    public function normalize(?string $text, int $workspaceId): array
    {
        if ($text === null || $text === '') {
            return ['text' => $text, 'user_ids' => []];
        }

        preg_match_all(self::TOKEN, $text, $matches);
        $candidateIds = array_values(array_unique(array_map('intval', $matches[2])));

        $allowed = $candidateIds === [] ? [] : DB::table('workspace_user')
            ->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->where('workspace_user.workspace_id', $workspaceId)
            ->where('users.is_active', true)
            ->whereIn('workspace_user.user_id', $candidateIds)
            ->pluck('workspace_user.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $clean = (string) preg_replace_callback(self::TOKEN, function (array $m) use ($allowed) {
            return in_array((int) $m[2], $allowed, true) ? $m[0] : '@'.$m[1];
        }, $text);

        return ['text' => $clean, 'user_ids' => array_values(array_intersect($candidateIds, $allowed))];
    }

    /**
     * Make the record's mention rows match the given user ids.
     *
     * @param  Model&Mentionable  $record
     * @param  list<int>  $userIds
     * @return list<int> ids that were newly mentioned (for notifications)
     */
    public function sync(Model $record, array $userIds, ?User $actor): array
    {
        $rows = fn () => Mention::query()
            ->where('mentionable_type', $record->getMorphClass())
            ->where('mentionable_id', $record->getKey());

        $existing = $rows()->pluck('mentioned_user_id')->map(fn ($id) => (int) $id)->all();

        $added = array_values(array_diff($userIds, $existing));
        $removed = array_values(array_diff($existing, $userIds));

        if ($removed !== []) {
            $rows()->whereIn('mentioned_user_id', $removed)->delete();
        }

        foreach ($added as $userId) {
            Mention::create([
                'workspace_id' => $record->mentionWorkspaceId(),
                'mentioned_user_id' => $userId,
                'mentioned_by' => $actor?->getKey(),
                'mentionable_type' => $record->getMorphClass(),
                'mentionable_id' => $record->getKey(),
            ]);

            UserMentioned::dispatch($record, $userId, $actor);
        }

        return $added;
    }
}
