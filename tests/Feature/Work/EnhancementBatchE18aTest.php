<?php

namespace Tests\Feature\Work;

use App\Domain\Forms\FormSchema;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\InboxNotification;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E18a: form builder (E-53), form outputs (E-54), client intake (E-42).
 */
class EnhancementBatchE18aTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo('2026-10-15 09:00:00');
        $this->ws = Workspace::factory()->create(['key' => 'BRW', 'name' => 'Brewhouse']);
        $this->ws->forceFill(['custom_fields' => [['key' => 'budget', 'label' => 'Budget', 'type' => 'number']]])->save();
        $this->lead = User::factory()->withRole('member')->create(['name' => 'Lia Lead']);
        $this->member = User::factory()->withRole('member')->create();
        $this->ws->members()->attach([
            $this->lead->id => ['role' => Workspace::ROLE_LEAD],
            $this->member->id => ['role' => Workspace::ROLE_MEMBER],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return ['sections' => [
            ['title' => 'The request', 'fields' => [
                ['label' => 'Request type', 'type' => 'select', 'required' => true, 'options' => ['Video', 'Banner']],
                ['label' => 'Summary', 'type' => 'textarea', 'required' => true],
                ['label' => 'Budget', 'key' => 'budget', 'type' => 'number'],
                ['label' => 'Needed by', 'key' => 'needed_by', 'type' => 'date'],
                ['label' => 'Brief', 'key' => 'brief', 'type' => 'file', 'max_files' => 2],
            ]],
            ['title' => 'Video details', 'show_if' => ['field' => 'request_type', 'op' => 'eq', 'value' => 'Video'], 'fields' => [
                ['label' => 'Length (seconds)', 'key' => 'length', 'type' => 'number', 'required' => true],
                ['label' => 'Channels', 'type' => 'multiselect', 'options' => ['Instagram', 'TikTok']],
            ]],
        ]];
    }

    private function publishedForm(string $type = 'task'): Form
    {
        $this->actingAs($this->lead)->post(route('workspaces.forms.store', $this->ws), ['name' => 'Creative request'])->assertRedirect();
        $form = Form::query()->latest('id')->firstOrFail();
        $this->actingAs($this->lead)->put(route('workspaces.forms.update', [$this->ws, $form]), [
            'name' => 'Creative request', 'description' => 'Tell us what you need.',
            'draft' => $this->schema(),
            'output' => ['type' => $type, 'title' => '{{request_type}}: {{summary}}', 'priority' => 'high', 'due_in_days' => 7,
                'map' => ['custom.budget' => 'budget', 'due_on' => 'needed_by', 'work.channel' => 'channels', 'custom.nope' => 'budget']],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->lead)->post(route('workspaces.forms.publish', [$this->ws, $form]))->assertSessionHasNoErrors();

        return $form->fresh() ?? $form;
    }

    // E-53 -----------------------------------------------------------------

    public function test_the_schema_is_cleaned_and_conditions_only_look_upwards()
    {
        $clean = FormSchema::normalize(['sections' => [['fields' => [
            ['label' => 'Your goal', 'type' => 'text', 'show_if' => ['field' => 'later', 'op' => 'eq', 'value' => 'x']],
            ['label' => 'Your goal', 'type' => 'text'],
            ['label' => 'Later', 'type' => 'yesno'],
        ]]]]);
        $fields = $clean['sections'][0]['fields'];
        $this->assertSame(['your_goal', 'your_goal_2', 'later'], array_column($fields, 'key'));
        $this->assertNull($fields[0]['show_if']); // pointed at a field below it

        $this->expectException(ValidationException::class);
        FormSchema::normalize(['sections' => [['fields' => [['label' => 'Pick', 'type' => 'select', 'options' => []]]]]]);
    }

    public function test_hidden_fields_are_never_required_and_their_answers_are_dropped()
    {
        $schema = FormSchema::normalize($this->schema());

        // Banner: the video section is hidden, so its required "length" is not asked for.
        $clean = FormSchema::validate($schema, ['request_type' => 'Banner', 'summary' => ' Holiday banner ', 'length' => 30]);
        $this->assertSame(['request_type' => 'Banner', 'summary' => 'Holiday banner'], $clean);

        try {
            FormSchema::validate($schema, ['request_type' => 'Video', 'summary' => 'Reel', 'channels' => ['Facebook']]);
            $this->fail('Video needs a length and valid channels');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('answers.length', $e->errors());
            $this->assertArrayHasKey('answers.channels.0', $e->errors());
        }
    }

    public function test_only_leads_build_and_publish_and_each_publish_is_a_version()
    {
        $this->actingAs($this->member)->post(route('workspaces.forms.store', $this->ws), ['name' => 'X'])->assertForbidden();
        $form = $this->publishedForm();
        $this->assertSame(1, $form->currentVersion?->version);
        $this->assertSame(['custom.budget' => 'budget', 'due_on' => 'needed_by', 'work.channel' => 'channels'], $form->output['map']); // unknown targets dropped

        $this->actingAs($this->member)->get(route('workspaces.forms.edit', [$this->ws, $form]))->assertForbidden();
        $this->actingAs($this->lead)->get(route('workspaces.forms.edit', [$this->ws, $form]))
            ->assertInertia(fn (Assert $page) => $page->component('forms/Builder')->where('form.version', 1));
    }

    // E-54 -----------------------------------------------------------------

    public function test_a_submission_creates_exactly_one_task_with_mapped_fields()
    {
        $form = $this->publishedForm();
        $body = ['answers' => ['request_type' => 'Video', 'summary' => 'Holiday reel', 'budget' => '25000', 'needed_by' => '2026-11-20', 'length' => '30', 'channels' => ['Instagram']], 'idempotency_key' => 'k-123'];

        $this->actingAs($this->member)->post(route('workspaces.forms.submit', [$this->ws, $form]), $body)->assertSessionHasNoErrors();
        $this->actingAs($this->member)->post(route('workspaces.forms.submit', [$this->ws, $form]), $body)->assertSessionHasNoErrors(); // retry

        $this->assertSame(1, FormSubmission::query()->count());
        $task = Task::query()->where('workspace_id', $this->ws->id)->firstOrFail();
        $this->assertSame(['Video: Holiday reel', 'high', '2026-11-20', 25000, 'Instagram'], [$task->title, $task->priority, $task->due_on?->toDateString(), $task->custom_values['budget'] ?? null, $task->work_details['channel'] ?? null]);
        $this->assertStringContainsString('**Length (seconds):** 30', (string) $task->description);
        $this->assertSame($task->id, FormSubmission::query()->value('task_id'));
        $this->assertSame(1, InboxNotification::query()->where('notifiable_id', $this->lead->id)->where('kind', 'intake')->count());

        $this->actingAs($this->member)->post(route('workspaces.forms.submit', [$this->ws, $form]), ['answers' => ['request_type' => 'Video', 'summary' => 'x']])
            ->assertSessionHasErrors('answers.length');
    }

    public function test_old_submissions_keep_their_version_and_submissions_export()
    {
        $form = $this->publishedForm('record');
        $this->actingAs($this->member)->post(route('workspaces.forms.submit', [$this->ws, $form]), ['answers' => ['request_type' => 'Banner', 'summary' => 'Old one']])->assertSessionHasNoErrors();
        $this->assertSame(0, Task::query()->count()); // record only

        $schema = $this->schema();
        $schema['sections'][0]['fields'][1]['label'] = 'What do you need?';
        $this->actingAs($this->lead)->put(route('workspaces.forms.update', [$this->ws, $form]), ['name' => 'Creative request', 'draft' => $schema, 'output' => ['type' => 'record']]);
        $this->actingAs($this->lead)->post(route('workspaces.forms.publish', [$this->ws, $form]));

        $this->actingAs($this->member)->get(route('workspaces.forms.submissions', [$this->ws, $form]))
            ->assertInertia(fn (Assert $page) => $page->component('forms/Submissions')
                ->where('form.version', 2)
                ->where('submissions.0.version', 1)
                ->where('submissions.0.answers.1.label', 'Summary'));

        $xlsx = $this->actingAs($this->member)->get(route('workspaces.forms.submissions', [$this->ws, $form, 'format' => 'xlsx']))->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $xlsx->headers->get('Content-Type'));
    }

    // E-42 -----------------------------------------------------------------

    public function test_a_client_submits_the_public_form_and_gets_a_reference()
    {
        $form = $this->publishedForm();
        $this->actingAs($this->lead)->post(route('workspaces.forms.link', [$this->ws, $form]))->assertRedirect();
        $token = (string) $form->fresh()?->public_token;
        auth()->logout();

        $page = $this->get(route('intake.show', $token))->assertOk()->assertSee('Creative request')->assertSee('Your name')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('noindex', (string) $page->headers->get('X-Robots-Tag'));
        preg_match('/name="stamp" value="([^"]+)"/', (string) $page->getContent(), $m);
        $stamp = html_entity_decode($m[1]);

        $answers = ['request_type' => 'Banner', 'summary' => 'Store opening banner'];
        // Too fast (a bot) is refused; the honeypot pretends to succeed but saves nothing.
        $this->post(route('intake.submit', $token), ['stamp' => $stamp, 'submitter_name' => 'Ana', 'answers' => $answers])->assertSessionHasErrors('form');
        $this->travel(10)->seconds();
        $this->post(route('intake.submit', $token), ['stamp' => $stamp, 'submitter_name' => 'Bot', 'website' => 'spam.example', 'answers' => $answers])->assertRedirect(route('intake.thanks', $token));
        $this->assertSame(0, FormSubmission::query()->count());

        $this->post(route('intake.submit', $token), [
            'stamp' => $stamp, 'submitter_name' => 'Ana Cruz', 'idempotency_key' => 'pub-1', 'answers' => $answers,
            'files' => ['brief' => [UploadedFile::fake()->createWithContent('brief.pdf', '%PDF-1.4 brief')]],
        ])->assertRedirect(route('intake.thanks', $token));

        $submission = FormSubmission::query()->firstOrFail();
        $this->assertSame(['Ana Cruz', null], [$submission->submitter_name, $submission->submitted_by]);
        $task = Task::query()->findOrFail($submission->task_id);
        $this->assertSame($this->lead->id, $task->reporter_id); // recorded as the form's owner
        $this->assertSame(1, Attachment::query()->where('attachable_id', $task->id)->where('original_name', 'brief.pdf')->count());
        $this->get(route('intake.thanks', $token))->assertOk();

        $this->actingAs($this->lead)->delete(route('workspaces.forms.unlink', [$this->ws, $form]))->assertRedirect();
        auth()->logout();
        $this->get(route('intake.show', $token))->assertNotFound();
    }

    public function test_the_reference_is_shown_after_a_public_submit()
    {
        $form = $this->publishedForm('record');
        $this->actingAs($this->lead)->post(route('workspaces.forms.link', [$this->ws, $form]));
        $token = (string) $form->fresh()?->public_token;
        auth()->logout();
        $page = $this->get(route('intake.show', $token));
        preg_match('/name="stamp" value="([^"]+)"/', (string) $page->getContent(), $m);
        $this->travel(5)->seconds();

        $this->followingRedirects()->post(route('intake.submit', $token), [
            'stamp' => html_entity_decode($m[1]), 'submitter_name' => 'Ana', 'answers' => ['request_type' => 'Banner', 'summary' => 'A banner'],
        ])->assertOk()->assertSee(FormSubmission::query()->value('reference'));
    }
}
