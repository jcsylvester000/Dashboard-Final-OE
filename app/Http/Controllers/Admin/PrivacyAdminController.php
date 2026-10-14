<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Privacy\PrivacyProgram;
use App\Http\Controllers\Controller;
use App\Models\PrivacyAcknowledgement;
use App\Models\PrivacyIncident;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * E-99: admins publish the staff privacy notice, see who acknowledged it, keep the DPO
 * contact, and keep the personal data incident log (admins only; every change logged).
 */
class PrivacyAdminController extends Controller
{
    public function __construct(
        private readonly PrivacyProgram $privacy,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(): Response
    {
        $notice = $this->privacy->get();
        $acks = PrivacyAcknowledgement::query()->where('version', $notice['version'])->pluck('acknowledged_at', 'user_id');
        $people = User::query()->active()->orderBy('name')->get(['id', 'name', 'title']);

        return Inertia::render('admin/Privacy', [
            'notice' => $notice,
            'acknowledgements' => [
                'total' => $people->count(),
                'done' => $people->filter(fn (User $u) => $acks->has($u->id))->count(),
                'people' => $people->map(fn (User $u): array => [
                    'id' => $u->id, 'name' => $u->name, 'title' => $u->title,
                    'acknowledged_at' => $acks->has($u->id) ? (string) $acks->get($u->id) : null,
                ])->values(),
            ],
            'incidents' => PrivacyIncident::query()->with('author:id,name')->latest('discovered_at')->limit(100)->get()
                ->map(fn (PrivacyIncident $i): array => [
                    'id' => $i->id, 'title' => $i->title, 'description' => $i->description, 'data_involved' => $i->data_involved,
                    'people_affected' => $i->people_affected, 'severity' => $i->severity, 'status' => $i->status, 'actions' => $i->actions,
                    'discovered_at' => $i->discovered_at->toIso8601String(), 'occurred_at' => $i->occurred_at?->toIso8601String(),
                    'npc_notified_at' => $i->npc_notified_at?->toIso8601String(), 'people_notified_at' => $i->people_notified_at?->toIso8601String(),
                    'notify_by' => $i->discovered_at->copy()->addHours(PrivacyIncident::NOTIFY_WITHIN_HOURS)->toIso8601String(),
                    'by' => $i->author?->name,
                ])->values(),
            'severities' => PrivacyIncident::SEVERITIES,
            'statuses' => PrivacyIncident::STATUSES,
            'notifyHours' => PrivacyIncident::NOTIFY_WITHIN_HOURS,
        ]);
    }

    public function publish(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'min:20', 'max:20000']]);
        /** @var User $by */
        $by = $request->user();
        $version = $this->privacy->publish((string) $data['title'], (string) $data['body'], $by);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Version :v published. Everyone is asked to acknowledge it.', ['v' => $version])]);

        return back();
    }

    public function dpo(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dpo_name' => ['nullable', 'string', 'max:120'],
            'dpo_email' => ['nullable', 'email', 'max:190'],
            'dpo_phone' => ['nullable', 'string', 'max:40'],
        ]);
        /** @var User $by */
        $by = $request->user();
        $this->privacy->saveDpo($data['dpo_name'] ?? null, $data['dpo_email'] ?? null, $data['dpo_phone'] ?? null, $by);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('DPO contact saved.')]);

        return back();
    }

    public function storeIncident(Request $request): RedirectResponse
    {
        /** @var User $by */
        $by = $request->user();
        $incident = new PrivacyIncident($this->incidentData($request));
        $incident->forceFill(['created_by' => $by->id, 'updated_by' => $by->id])->save();
        // No personal details in the audit row: the id, severity and status only.
        $this->activity->log('privacy.incident-recorded', $incident, ['severity' => $incident->severity, 'status' => $incident->status], $by);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Incident recorded.')]);

        return back();
    }

    public function updateIncident(Request $request, PrivacyIncident $privacyIncident): RedirectResponse
    {
        /** @var User $by */
        $by = $request->user();
        $before = $privacyIncident->only(['severity', 'status']);
        $privacyIncident->fill($this->incidentData($request))->forceFill(['updated_by' => $by->id])->save();
        $this->activity->log('privacy.incident-updated', $privacyIncident, ['before' => $before, 'after' => $privacyIncident->only(['severity', 'status'])], $by);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Incident updated.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'discovered_at' => ['required', 'date'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:discovered_at'],
            'description' => ['required', 'string', 'max:10000'],
            'data_involved' => ['nullable', 'string', 'max:500'],
            'people_affected' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'severity' => ['required', Rule::in(array_keys(PrivacyIncident::SEVERITIES))],
            'status' => ['required', Rule::in(array_keys(PrivacyIncident::STATUSES))],
            'npc_notified_at' => ['nullable', 'date'],
            'people_notified_at' => ['nullable', 'date'],
            'actions' => ['nullable', 'string', 'max:10000'],
        ]);
    }
}
