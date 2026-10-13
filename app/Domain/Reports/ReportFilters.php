<?php

namespace App\Domain\Reports;

use App\Domain\Identity\Permissions;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Who may see which workspaces in reports, plus the request's filters.
 *
 * Access: `reports.view-all` (or `workspaces.manage`) => every workspace;
 * otherwise only workspaces where the user is owner or lead. Members get none.
 */
final class ReportFilters
{
    /** Longest date range a report may cover. */
    public const MAX_DAYS = 366;

    /**
     * @param  list<int>|null  $allowedWorkspaceIds  null = all workspaces
     */
    public function __construct(
        public readonly ?array $allowedWorkspaceIds,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $workspaceId = null,
        public readonly ?int $departmentId = null,
        public readonly ?int $projectId = null,
    ) {}

    /**
     * Workspace ids the user may report on; null means all, [] means none.
     *
     * @return list<int>|null
     */
    public static function allowedWorkspaces(User $user): ?array
    {
        if ($user->can(Permissions::REPORTS_VIEW_ALL) || $user->can(Permissions::WORKSPACES_MANAGE)) {
            return null;
        }

        return DB::table('workspace_user')
            ->where('user_id', $user->id)
            ->whereIn('role', [Workspace::ROLE_OWNER, Workspace::ROLE_LEAD])
            ->pluck('workspace_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public static function canViewReports(User $user): bool
    {
        return self::allowedWorkspaces($user) !== [];
    }

    /**
     * Build from query string: workspace, department, from, to (Y-m-d).
     * Default range: the last 8 full weeks up to today.
     */
    public static function fromRequest(Request $request, User $user): self
    {
        $allowed = self::allowedWorkspaces($user);
        abort_if($allowed === [], 403, 'Reports are for workspace leads and admins.');

        $data = $request->validate([
            'workspace' => ['nullable', 'integer', $allowed === null ? Rule::exists('workspaces', 'id') : Rule::in($allowed)],
            'department' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            // S1-27: one project or campaign (only inside workspaces the user may report on).
            'project' => ['nullable', 'integer', $allowed === null ? Rule::exists('projects', 'id') : Rule::exists('projects', 'id')->whereIn('workspace_id', $allowed)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : now()->toImmutable();
        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])
            : $to->subWeeks(7)->startOfWeek(CarbonImmutable::MONDAY);

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS);
        }

        return new self(
            $allowed,
            $from->startOfDay(),
            $to->endOfDay(),
            isset($data['workspace']) ? (int) $data['workspace'] : null,
            isset($data['department']) ? (int) $data['department'] : null,
            isset($data['project']) ? (int) $data['project'] : null,
        );
    }

    /**
     * @return array{workspace: int|null, department: int|null, project: int|null, from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'workspace' => $this->workspaceId,
            'department' => $this->departmentId,
            'project' => $this->projectId,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ];
    }
}
