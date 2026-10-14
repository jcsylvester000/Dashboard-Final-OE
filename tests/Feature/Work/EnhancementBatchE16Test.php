<?php

namespace Tests\Feature\Work;

use App\Domain\Integrations\ChatNotifier;
use App\Domain\Integrations\GitHubEvents;
use App\Domain\Work\LinkPreview;
use App\Models\ChatChannel;
use App\Models\CodeRepository;
use App\Models\GitHubDelivery;
use App\Models\InboxNotification;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskCodeLink;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E16 (decisions D-04, D-05): GitHub link-up (E-24), preview URL
 * status (E-26) and optional chat notifications (E-41).
 */
class EnhancementBatchE16Test extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    private Workspace $ws;

    private User $lead;

    private User $dev;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo('2026-10-15 09:00:00');
        config(['services.github.webhook_secret' => self::SECRET]);
        $this->ws = Workspace::factory()->create(['key' => 'BRW', 'name' => 'Brewhouse']);
        $this->lead = User::factory()->withRole('member')->create();
        $this->dev = User::factory()->withRole('member')->create();
        $this->ws->members()->attach([
            $this->lead->id => ['role' => Workspace::ROLE_LEAD],
            $this->dev->id => ['role' => Workspace::ROLE_MEMBER],
        ]);
        $this->task = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->dev->id, 'number' => 24, 'title' => 'Fix cart', 'status_id' => TaskStatus::idFor('in-progress')]);
        LinkPreview::$resolver = fn (string $host): array => ['93.184.216.34'];
    }

    protected function tearDown(): void
    {
        LinkPreview::$resolver = null;
        parent::tearDown();
    }

    // E-24 -----------------------------------------------------------------

    public function test_task_keys_are_found_in_titles_branches_and_commits()
    {
        $this->assertSame([24, 7], GitHubEvents::taskNumbers('BRW', 'brw-24 fix and BRW-7, not XBRW-9 or BRW-12345678'));
        $this->assertSame([], GitHubEvents::taskNumbers('BRW', 'no key here'));
    }

    public function test_unsigned_or_unconfigured_webhooks_are_refused()
    {
        $body = json_encode(['zen' => 'hi']);
        $this->call('POST', route('webhooks.github'), [], [], [], ['HTTP_X_GITHUB_EVENT' => 'ping', 'HTTP_X_GITHUB_DELIVERY' => 'd-1', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=wrong', 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertUnauthorized();

        config(['services.github.webhook_secret' => '']);
        $this->hook('ping', ['zen' => 'hi'], 'd-2')->assertStatus(503);
        $this->assertSame(0, GitHubDelivery::query()->count());
    }

    public function test_an_opened_pr_links_the_task_and_moves_it_to_review_once()
    {
        $this->linkRepo();

        $this->hook('pull_request', $this->pr('opened'), 'delivery-1')->assertStatus(202);

        $link = TaskCodeLink::query()->where('kind', 'pr')->firstOrFail();
        $this->assertSame(['#12', 'BRW-24 Fix cart', 'open', ['nico'], 'ana'], [$link->ref, $link->title, $link->state, $link->reviewers, $link->author]);
        $this->assertTrue(TaskCodeLink::query()->where('kind', 'branch')->where('ref', 'brw-24-fix-cart')->exists());
        $this->assertSame(TaskStatus::idFor('in-review'), $this->task->fresh()?->status_id);
        $this->assertSame('done', GitHubDelivery::query()->firstOrFail()->status);

        // The same delivery again (replay / redelivery) is acknowledged and ignored.
        $this->hook('pull_request', $this->pr('opened'), 'delivery-1')->assertOk()->assertJsonPath('message', 'Already received.');
        $this->assertSame(1, GitHubDelivery::query()->count());

        $this->actingAs($this->dev)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn (Assert $page) => $page->where('task.code_links', fn ($links) => collect($links)->contains('ref', '#12')));
    }

    public function test_merge_respects_guards_and_reports_a_blocked_move()
    {
        $this->linkRepo();
        TaskChecklistItem::query()->create(['task_id' => $this->task->id, 'label' => 'QA on staging', 'required' => true, 'position' => 1]);

        $this->hook('pull_request', $this->pr('closed', merged: true), 'delivery-2')->assertStatus(202);

        $this->assertSame(TaskStatus::idFor('in-progress'), $this->task->fresh()?->status_id);
        $this->assertStringContainsString('BRW-24 not moved', (string) GitHubDelivery::query()->firstOrFail()->result);
        $this->assertSame('merged', TaskCodeLink::query()->where('kind', 'pr')->value('state'));
    }

    public function test_checks_and_pushes_update_links_and_unknown_repos_are_ignored()
    {
        $this->linkRepo();
        $this->hook('pull_request', $this->pr('opened'), 'delivery-3');

        $this->hook('check_suite', ['action' => 'completed', 'repository' => ['full_name' => 'OverEasy/brewhouse-site'], 'check_suite' => ['head_sha' => 'abc123', 'conclusion' => 'failure']], 'delivery-4');
        $this->assertSame('failure', TaskCodeLink::query()->where('kind', 'pr')->value('checks'));

        $this->hook('push', [
            'ref' => 'refs/heads/main', 'repository' => ['full_name' => 'overeasy/brewhouse-site'],
            'commits' => [['id' => str_repeat('f', 40), 'message' => "BRW-24 handle empty cart\n\nlong body", 'url' => 'https://github.com/overeasy/brewhouse-site/commit/fff']],
        ], 'delivery-5');
        $commit = TaskCodeLink::query()->where('kind', 'commit')->firstOrFail();
        $this->assertSame(['ffffffffffff', 'BRW-24 handle empty cart'], [$commit->ref, $commit->title]);

        $this->hook('pull_request', [...$this->pr('opened'), 'repository' => ['full_name' => 'someone/else']], 'delivery-6');
        $this->assertSame('ignored', GitHubDelivery::query()->where('delivery_id', 'delivery-6')->value('status'));
    }

    public function test_only_leads_link_repositories()
    {
        $this->actingAs($this->dev)->post(route('workspaces.repositories.store', $this->ws), ['full_name' => 'overeasy/site'])->assertForbidden();
        $this->actingAs($this->lead)->post(route('workspaces.repositories.store', $this->ws), ['full_name' => 'not a repo'])->assertSessionHasErrors('full_name');
        $this->actingAs($this->lead)->post(route('workspaces.repositories.store', $this->ws), ['full_name' => ' OverEasy/Site/ ', 'on_open_status' => 'in-review'])
            ->assertSessionHasNoErrors();
        $this->assertSame('overeasy/site', CodeRepository::query()->value('full_name'));

        $other = Workspace::factory()->create(['key' => 'OTH']);
        $other->members()->attach($this->lead->id, ['role' => Workspace::ROLE_LEAD]);
        $this->actingAs($this->lead)->post(route('workspaces.repositories.store', $other), ['full_name' => 'overeasy/site'])->assertSessionHasErrors('full_name');
    }

    // E-26 -----------------------------------------------------------------

    public function test_preview_link_is_checked_and_a_break_alerts_the_assignee_once()
    {
        Http::fake(['staging.brewhouse.test/*' => Http::sequence()->push('ok', 200)->push('down', 503)->push('down', 503)->push('ok', 200)]);

        $this->actingAs($this->dev)->put(route('workspaces.tasks.preview', [$this->ws, $this->task]), ['preview_url' => 'https://staging.brewhouse.test/'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['up', 200], [$this->task->fresh()?->preview_status, $this->task->fresh()?->preview_http]);

        $this->artisan('previews:check')->assertSuccessful();
        $this->artisan('previews:check')->assertSuccessful();
        $this->assertSame('down', $this->task->fresh()?->preview_status);
        $this->assertSame(1, InboxNotification::query()->where('notifiable_id', $this->dev->id)->where('kind', 'preview')->count());

        $this->artisan('previews:check')->assertSuccessful(); // back up: the next break alerts again
        $this->assertSame('up', $this->task->fresh()?->preview_status);
        $this->assertNull($this->task->fresh()?->preview_alerted_at);

        $this->actingAs($this->dev)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn (Assert $page) => $page->where('task.preview.status', 'up'));
    }

    public function test_private_preview_links_are_refused()
    {
        LinkPreview::$resolver = fn (string $host): array => ['10.0.0.8'];

        $this->actingAs($this->dev)->put(route('workspaces.tasks.preview', [$this->ws, $this->task]), ['preview_url' => 'http://intranet.local/'])
            ->assertSessionHasErrors('preview_url');
        $this->actingAs($this->dev)->put(route('workspaces.tasks.preview', [$this->ws, $this->task]), ['preview_url' => 'ftp://x.test/'])
            ->assertSessionHasErrors('preview_url');
    }

    // E-41 -----------------------------------------------------------------

    public function test_only_slack_and_teams_workflow_links_are_accepted()
    {
        ChatNotifier::assertWebhook('slack', 'https://hooks.slack.com/services/T0/B0/xyz');
        ChatNotifier::assertWebhook('teams', 'https://prod-12.westus.logic.azure.com/workflows/abc');

        foreach ([['slack', 'http://hooks.slack.com/services/x'], ['slack', 'https://evil.test/hooks.slack.com'], ['teams', 'https://acme.webhook.office.com/webhookb2/x'], ['teams', 'https://hooks.slack.com/services/x']] as [$provider, $url]) {
            try {
                ChatNotifier::assertWebhook($provider, $url);
                $this->fail("{$url} should be refused");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_channel_stays_off_until_a_test_arrives_and_then_posts_title_and_link_only()
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        $url = 'https://hooks.slack.com/services/T0/B0/secretsecret';

        $this->actingAs($this->dev)->post(route('workspaces.chat-channels.store', $this->ws), ['provider' => 'slack', 'name' => '#reviews', 'webhook_url' => $url, 'events' => ['review_requested']])->assertForbidden();
        $this->actingAs($this->lead)->post(route('workspaces.chat-channels.store', $this->ws), ['provider' => 'slack', 'name' => '#reviews', 'webhook_url' => $url, 'events' => ['review_requested', 'approved']])
            ->assertSessionHasNoErrors();
        $channel = ChatChannel::query()->firstOrFail();
        $this->assertFalse($channel->enabled);
        $this->assertNotSame($url, DB::table('chat_channels')->value('webhook_url')); // encrypted at rest

        $this->actingAs($this->lead)->put(route('workspaces.chat-channels.update', [$this->ws, $channel]), ['enabled' => true])->assertSessionHasErrors('enabled');
        $this->actingAs($this->lead)->post(route('workspaces.chat-channels.test', [$this->ws, $channel]))->assertRedirect();
        $this->assertNotNull($channel->fresh()?->last_ok_at);
        $this->actingAs($this->lead)->put(route('workspaces.chat-channels.update', [$this->ws, $channel]), ['enabled' => true])->assertSessionHasNoErrors();

        $this->actingAs($this->lead)->get(route('workspaces.edit', $this->ws))
            ->assertInertia(fn (Assert $page) => $page->where('chatChannels.0.url', 'hooks.slack.com/…cret')->missing('chatChannels.0.webhook_url'));

        // Ready for review -> one message with the key, title and link; nothing else.
        $this->actingAs($this->dev)->put(route('workspaces.tasks.update', [$this->ws, $this->task]), ['status_id' => TaskStatus::idFor('in-review')])->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://hooks.slack.com/')
            && array_keys($r->data()) === ['text']
            && str_contains((string) $r->data()['text'], 'Ready for review: BRW-24 Fix cart')
            && str_contains((string) $r->data()['text'], route('workspaces.tasks.show', [$this->ws, $this->task])));
        Http::assertSentCount(2); // the test + the review request
    }

    public function test_teams_gets_an_adaptive_card()
    {
        Http::fake(['*.logic.azure.com/*' => Http::response('', 202)]);
        $channel = ChatChannel::query()->create(['workspace_id' => $this->ws->id, 'provider' => 'teams', 'name' => 'Reviews', 'webhook_url' => 'https://prod-1.westus.logic.azure.com/workflows/x', 'events' => ['done'], 'enabled' => true]);

        $this->assertNull(app(ChatNotifier::class)->send($channel, 'Task done: BRW-24 Fix cart', 'https://app.test/t/BRW-24'));

        Http::assertSent(fn (HttpRequest $r) => $r->data()['type'] === 'message'
            && $r->data()['attachments'][0]['content']['type'] === 'AdaptiveCard'
            && $r->data()['attachments'][0]['content']['actions'][0]['url'] === 'https://app.test/t/BRW-24');
    }

    // helpers -----------------------------------------------------------------

    private function linkRepo(): void
    {
        CodeRepository::query()->create(['workspace_id' => $this->ws->id, 'full_name' => 'overeasy/brewhouse-site', 'on_open_status' => 'in-review', 'on_merge_status' => 'done', 'created_by' => $this->lead->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pr(string $action, bool $merged = false): array
    {
        return [
            'action' => $action,
            'repository' => ['full_name' => 'OverEasy/brewhouse-site'],
            'pull_request' => [
                'number' => 12, 'title' => 'BRW-24 Fix cart', 'html_url' => 'https://github.com/overeasy/brewhouse-site/pull/12',
                'state' => $action === 'closed' ? 'closed' : 'open', 'draft' => false, 'merged' => $merged,
                'head' => ['ref' => 'brw-24-fix-cart', 'sha' => 'abc123'],
                'user' => ['login' => 'ana'], 'requested_reviewers' => [['login' => 'nico']],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hook(string $event, array $payload, string $delivery): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', route('webhooks.github'), [], [], [], [
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) config('services.github.webhook_secret')),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}
