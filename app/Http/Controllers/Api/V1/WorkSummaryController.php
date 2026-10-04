<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\WorkReport;
use App\Domain\Billing\WorkSummaryAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/workspaces/{id}/work-summary?from=&to=&department=
 * Who worked on what, by task, with Marketing / SEO details - for dashboards and AI summaries.
 */
class WorkSummaryController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, WorkReport $report): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(WorkSummaryAccess::allows($user, $workspace), 403, 'Work summaries are for workspace leads and Finance.');

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department' => ['nullable', Rule::exists('departments', 'slug')],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        abort_if($from->diffInDays($to) > 366, 422, 'The range can be at most one year.');

        return response()->json([
            'data' => [
                'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'department' => $data['department'] ?? null,
                ...$report->forPeriod($workspace->id, $from, $to, $data['department'] ?? null),
            ],
        ]);
    }
}
