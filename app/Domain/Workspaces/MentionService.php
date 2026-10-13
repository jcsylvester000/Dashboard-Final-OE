<?php

namespace App\Domain\Workspaces;

use App\Events\Work\UserMentioned;
use App\Models\Contracts\Mentionable;
use App\Models\Mention;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @mentions use the token  @[Display Name](user:12)  inside plain text, and
 *
 * @[Department](dept:3) to tag every member of a department on that client.
 * The editor inserts tokens; the server keeps only tokens for active members
 * of the record's workspace (others become plain "@Name") and stores one
 * mentions row per tagged person. Each new mention fires UserMentioned (P4 alerts).
 */
class MentionService
{
    public const TOKEN = '/@\[([^\]\r\n]{1,80})\]\(user:(\d{1,10})\)/u';

    /** @[SEO](dept:5) tags a whole department. */
    public const DEPARTMENT_TOKEN = '/@\[([^\]\r\n]{1,80})\]\(dept:(\d{1,10})\)/u';

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

        $text = $this->linkTypedNames($text, $workspaceId);
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

        // @Department tokens tag everyone in that department who works on this client.
        preg_match_all(self::DEPARTMENT_TOKEN, $clean, $deptMatches);
        $deptIds = array_values(array_unique(array_map('intval', $deptMatches[2])));
        $validDepts = $deptIds === [] ? [] : DB::table('departments')->whereIn('id', $deptIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $clean = (string) preg_replace_callback(self::DEPARTMENT_TOKEN, function (array $m) use ($validDepts) {
            return in_array((int) $m[2], $validDepts, true) ? $m[0] : '@'.$m[1];
        }, $clean);

        $userIds = array_values(array_intersect($candidateIds, $allowed));
        if ($validDepts !== []) {
            $userIds = array_values(array_unique([...$userIds, ...$this->departmentMembers($validDepts, $workspaceId)]));
        }

        return ['text' => $clean, 'user_ids' => $userIds];
    }

    /**
     * S3-03: a typed "@Maria Santos" (not picked from the list) becomes a mention when it
     * matches an active member's full name, or a first name only one member has. "@SEO"
     * matches a department. Longest names first so "@Ana Cruz" wins over "@Ana".
     */
    private function linkTypedNames(string $text, int $workspaceId): string
    {
        if (! str_contains($text, '@')) {
            return $text;
        }

        /** @var array<string, string> $targets lower-case name => token */
        $targets = [];
        $members = DB::table('workspace_user')
            ->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->where('workspace_user.workspace_id', $workspaceId)
            ->where('users.is_active', true)
            ->get(['users.id', 'users.name']);

        $firstNames = [];
        foreach ($members as $m) {
            $name = trim((string) $m->name);
            if ($name === '') {
                continue;
            }
            $targets[mb_strtolower($name)] = '@['.$name.'](user:'.(int) $m->id.')';
            $first = mb_strtolower(explode(' ', $name)[0]);
            $firstNames[$first][] = '@['.$name.'](user:'.(int) $m->id.')';
        }
        foreach ($firstNames as $first => $tokens) {
            if (count($tokens) === 1 && ! isset($targets[$first])) {
                $targets[$first] = $tokens[0];
            }
        }
        foreach (DB::table('departments')->get(['id', 'name']) as $d) {
            $targets[mb_strtolower((string) $d->name)] ??= '@['.(string) $d->name.'](dept:'.(int) $d->id.')';
        }

        uksort($targets, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($targets as $lower => $token) {
            // Not inside an existing token ("@[") and not part of an e-mail address or longer word.
            $pattern = '/(?<![\w@\]])@'.preg_quote($lower, '/').'(?![\w])/iu';
            $text = (string) preg_replace_callback($pattern, fn () => $token, $text);
        }

        return $text;
    }

    /**
     * Active workspace members in the given departments (primary or additional).
     *
     * @param  list<int>  $departmentIds
     * @return list<int>
     */
    private function departmentMembers(array $departmentIds, int $workspaceId): array
    {
        return DB::table('workspace_user')
            ->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->where('workspace_user.workspace_id', $workspaceId)
            ->where('users.is_active', true)
            ->where(fn ($q) => $q
                ->whereIn('users.primary_department_id', $departmentIds)
                ->orWhereExists(fn ($sub) => $sub->from('department_user')
                    ->whereColumn('department_user.user_id', 'users.id')
                    ->whereIn('department_user.department_id', $departmentIds)))
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
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
