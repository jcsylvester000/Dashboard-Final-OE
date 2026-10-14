<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Billing\Money;
use App\Domain\Billing\RateResolver;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Identity\Permissions;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * E-76 / E-110: set a project's budget. Leads set hours; Finance (billing.draft) sets hours
 * and the amount, which is always in the client's billing currency. Changing a budget
 * re-arms its alerts.
 */
class ProjectBudgetController extends Controller
{
    public function update(Request $request, Workspace $workspace, Project $project, WorkspaceAccess $access, RateResolver $rates, ActivityLogger $activity): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $finance = $user->can(Permissions::BILLING_DRAFT);
        abort_unless($finance || $access->canLead($user, $workspace), 403);

        $data = $request->validate([
            'budget_hours' => ['nullable', 'numeric', 'min:0.25', 'max:100000'],
            'budget_amount' => ['nullable', 'decimal:0,2', 'min:0.01', 'max:9999999999'],
        ]);

        $changes = [];
        $minutes = isset($data['budget_hours']) ? (int) round(((float) $data['budget_hours']) * 60) : null;
        if (array_key_exists('budget_hours', $data) && $minutes !== $project->budget_minutes) {
            $changes += ['budget_minutes' => $minutes, 'budget_hours_alerted_pct' => 0];
        }
        // Only Finance sees or changes amounts; a lead's save never touches them.
        if ($finance && array_key_exists('budget_amount', $data)) {
            $minor = $data['budget_amount'] !== null ? Money::parse((string) $data['budget_amount']) : null;
            if ($minor !== $project->budget_minor) {
                $changes += ['budget_minor' => $minor, 'budget_amount_alerted_pct' => 0,
                    'budget_currency' => $minor !== null ? $rates->currencyOf($workspace->id) : null];
            }
        }

        if ($changes !== []) {
            $before = $project->only(array_keys($changes));
            $project->forceFill($changes)->save();
            $activity->log('project.budget-changed', $project, ['workspace_id' => $workspace->id, 'before' => $before, 'after' => $changes]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Budget saved.')]);

        return back();
    }
}
