<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Integrations\ChatNotifier;
use App\Http\Controllers\Controller;
use App\Models\ChatChannel;
use App\Models\CodeRepository;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * E-24 / E-41 (leads): GitHub repositories and chat channels for one client.
 */
class IntegrationsController extends Controller
{
    public const MAX_CHANNELS = 5;

    public function __construct(private readonly ActivityLogger $activity) {}

    public function storeRepository(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $request->merge(['full_name' => strtolower(trim((string) $request->input('full_name'), " /\t"))]);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', Rule::unique('code_repositories', 'full_name')],
            'on_open_status' => ['nullable', Rule::in(['in-review'])],
            'on_merge_status' => ['nullable', Rule::in(['done'])],
        ], ['full_name.regex' => __('Use owner/repository, e.g. overeasy/brewhouse-site.'), 'full_name.unique' => __('That repository is already linked to a client.')]);
        if ($workspace->key === null) {
            throw ValidationException::withMessages(['full_name' => __('Give this client a task key first (Client details); PRs find tasks by it.')]);
        }
        /** @var User $by */
        $by = $request->user();
        $repo = CodeRepository::query()->create([...$data, 'workspace_id' => $workspace->id, 'created_by' => $by->id]);
        $this->activity->log('workspace.repository-linked', $workspace, ['repository' => $repo->full_name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Linked :r. Add the webhook in GitHub (see the steps below).', ['r' => $repo->full_name])]);

        return back();
    }

    public function updateRepository(Request $request, Workspace $workspace, CodeRepository $repository): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($repository->workspace_id === $workspace->id, 404);
        $data = $request->validate([
            'on_open_status' => ['nullable', Rule::in(['in-review'])],
            'on_merge_status' => ['nullable', Rule::in(['done'])],
        ]);
        /** @var User $by */
        $by = $request->user();
        // Moves happen as the lead who last set the rules.
        $repository->forceFill([...$data, 'created_by' => $by->id])->save();

        return back();
    }

    public function destroyRepository(Workspace $workspace, CodeRepository $repository): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($repository->workspace_id === $workspace->id, 404);
        $repository->delete();
        $this->activity->log('workspace.repository-unlinked', $workspace, ['repository' => $repository->full_name]);

        return back();
    }

    public function storeChannel(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_if(ChatChannel::query()->where('workspace_id', $workspace->id)->count() >= self::MAX_CHANNELS, 422, 'Up to '.self::MAX_CHANNELS.' chat channels per client.');
        $data = $request->validate([
            'provider' => ['required', Rule::in(['slack', 'teams'])],
            'name' => ['required', 'string', 'max:60'],
            'webhook_url' => ['required', 'string', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(ChatChannel::EVENTS))],
        ]);
        ChatNotifier::assertWebhook((string) $data['provider'], (string) $data['webhook_url']);
        /** @var User $by */
        $by = $request->user();
        $channel = ChatChannel::query()->create([...$data, 'events' => array_values(array_unique($data['events'])), 'workspace_id' => $workspace->id, 'enabled' => false, 'created_by' => $by->id]);
        $this->activity->log('workspace.chat-channel-added', $workspace, ['channel_id' => $channel->id, 'provider' => $channel->provider]); // never the URL
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel added (off). Send a test message, then turn it on.')]);

        return back();
    }

    public function updateChannel(Request $request, Workspace $workspace, ChatChannel $channel): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(ChatChannel::EVENTS))],
        ]);
        if (($data['enabled'] ?? false) && $channel->last_ok_at === null) {
            throw ValidationException::withMessages(['enabled' => __('Send a test message that arrives before turning the channel on.')]);
        }
        $channel->forceFill(array_filter([
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : null,
            'events' => isset($data['events']) ? array_values(array_unique($data['events'])) : null,
        ], fn ($v) => $v !== null))->save();
        $this->activity->log('workspace.chat-channel-updated', $workspace, ['channel_id' => $channel->id, 'enabled' => $channel->enabled]);

        return back();
    }

    public function destroyChannel(Workspace $workspace, ChatChannel $channel): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $channel->delete();
        $this->activity->log('workspace.chat-channel-removed', $workspace, ['channel_id' => $channel->id]);

        return back();
    }

    public function testChannel(Workspace $workspace, ChatChannel $channel, ChatNotifier $chat): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $error = $chat->send($channel, __('Test from OverEasy Dashboard: alerts for :c will appear here.', ['c' => $workspace->name]), route('workspaces.show', $workspace));
        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('Test message sent. You can turn the channel on.')]
            : ['type' => 'error', 'message' => __('The test did not arrive (:e). Check the webhook link.', ['e' => $error])]);

        return back();
    }
}
