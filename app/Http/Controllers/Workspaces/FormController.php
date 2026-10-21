<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Exports\ExportTable;
use App\Domain\Exports\ReportExporter;
use App\Domain\Forms\FormSchema;
use App\Domain\Forms\FormSubmitter;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * E-53 / E-54 / E-42: a client's forms. Leads build and publish them (each publish is a
 * new version) and choose what a submission does; members fill them in and see the
 * submissions; a public intake link can be turned on per form.
 */
class FormController extends Controller
{
    public function __construct(
        private readonly WorkspacePresenter $presenter,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('contribute', $workspace);

        return Inertia::render('forms/Index', [
            'workspace' => $this->presenter->header($workspace, $request->user()),
            'forms' => Form::query()->where('workspace_id', $workspace->id)->withCount('submissions')->with('currentVersion:id,version,published_at')
                ->orderBy('archived')->orderBy('name')->get()
                ->map(fn (Form $f): array => [
                    'id' => $f->id, 'name' => $f->name, 'description' => $f->description, 'archived' => $f->archived,
                    'version' => $f->currentVersion?->version, 'published_at' => $f->currentVersion?->published_at->toIso8601String(),
                    'submissions' => (int) $f->getAttribute('submissions_count'),
                    'public_url' => $f->public_token !== null ? route('intake.show', $f->public_token) : null,
                ])->values(),
            'canBuild' => Gate::allows('lead', $workspace),
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        /** @var User $user */
        $user = $request->user();
        $form = Form::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $data['name'],
            'draft' => ['sections' => [['id' => 's1', 'title' => null, 'show_if' => null, 'fields' => [
                ['key' => 'summary', 'label' => 'What do you need?', 'type' => 'textarea', 'required' => true, 'help' => null, 'options' => [], 'show_if' => null, 'max_files' => null],
            ]]]],
            'output' => ['type' => 'task', 'title' => 'Request: {{summary}}', 'department_id' => null, 'priority' => 'normal', 'due_in_days' => null, 'map' => [], 'notify_user_ids' => []],
            'created_by' => $user->id,
        ]);
        $this->activity->log('form.created', $workspace, ['form_id' => $form->id, 'name' => $form->name]);

        return to_route('workspaces.forms.edit', [$workspace, $form]);
    }

    public function edit(Request $request, Workspace $workspace, Form $form): Response
    {
        Gate::authorize('lead', $workspace);
        $this->own($workspace, $form);

        return Inertia::render('forms/Builder', [
            'workspace' => $this->presenter->header($workspace, $request->user()),
            'form' => [
                'id' => $form->id, 'name' => $form->name, 'description' => $form->description, 'draft' => $form->draft, 'output' => $form->output,
                'version' => $form->currentVersion?->version, 'public_url' => $form->public_token !== null ? route('intake.show', $form->public_token) : null,
                'archived' => $form->archived,
            ],
            'types' => FormSchema::TYPES,
            'outputTypes' => FormSubmitter::OUTPUT_TYPES,
            'departments' => Department::query()->active()->orderBy('position')->get(['id', 'name']),
            'customFields' => $workspace->custom_fields ?? [],
            'members' => $this->presenter->memberOptions($workspace),
        ]);
    }

    public function update(Request $request, Workspace $workspace, Form $form): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $this->own($workspace, $form);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'draft' => ['required', 'array'],
            'output' => ['required', 'array'],
        ]);
        $schema = FormSchema::normalize((array) $data['draft']);
        $form->forceFill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'draft' => $schema,
            'output' => FormSubmitter::normalizeOutput((array) $data['output'], $schema, $workspace),
        ])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved. Publish to make it live.')]);

        return back();
    }

    /** Freeze the draft as the next version; new submissions use it, old ones keep theirs. */
    public function publish(Request $request, Workspace $workspace, Form $form): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $this->own($workspace, $form);
        $schema = FormSchema::normalize($form->draft);
        if (FormSchema::fields($schema) === []) {
            return back()->withErrors(['draft' => __('Add at least one field before publishing.')]);
        }
        /** @var User $user */
        $user = $request->user();
        $version = DB::transaction(function () use ($form, $schema, $user, $workspace) {
            $next = 1 + (int) FormVersion::query()->where('form_id', $form->id)->max('version');
            $version = FormVersion::query()->create([
                'form_id' => $form->id, 'version' => $next, 'schema' => $schema,
                'output' => FormSubmitter::normalizeOutput($form->output, $schema, $workspace),
                'published_by' => $user->id, 'published_at' => now(),
            ]);
            $form->forceFill(['current_version_id' => $version->id, 'archived' => false])->save();

            return $version;
        });
        $this->activity->log('form.published', $workspace, ['form_id' => $form->id, 'version' => $version->version], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Published as version :v.', ['v' => $version->version])]);

        return back();
    }

    /** E-42: turn the public intake link on (a new link each time). */
    public function link(Workspace $workspace, Form $form): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $this->own($workspace, $form);
        abort_if($form->current_version_id === null, 422, 'Publish the form first.');
        $form->forceFill(['public_token' => Str::random(40)])->save();
        $this->activity->log('form.link-created', $workspace, ['form_id' => $form->id]);

        return back();
    }

    /** E-42: turn the public intake link off (the old link answers 404). */
    public function unlink(Workspace $workspace, Form $form): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $this->own($workspace, $form);
        $form->forceFill(['public_token' => null])->save();
        $this->activity->log('form.link-revoked', $workspace, ['form_id' => $form->id]);

        return back();
    }

    public function archive(Workspace $workspace, Form $form): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $this->own($workspace, $form);
        $form->forceFill(['archived' => ! $form->archived, 'public_token' => null])->save();

        return back();
    }

    public function fill(Request $request, Workspace $workspace, Form $form): Response
    {
        Gate::authorize('contribute', $workspace);
        $this->own($workspace, $form);
        abort_if($form->current_version_id === null || $form->archived, 404);

        return Inertia::render('forms/Fill', [
            'workspace' => $this->presenter->header($workspace, $request->user()),
            'form' => ['id' => $form->id, 'name' => $form->name, 'description' => $form->description, 'schema' => $form->currentVersion?->schema],
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function submit(Request $request, Workspace $workspace, Form $form, FormSubmitter $submitter): RedirectResponse
    {
        Gate::authorize('contribute', $workspace);
        $this->own($workspace, $form);
        /** @var User $user */
        $user = $request->user();
        $submission = $submitter->submit(
            $form,
            (array) $request->input('answers', []),
            self::uploads($request),
            $user,
            null,
            is_string($request->input('idempotency_key')) ? mb_substr((string) $request->input('idempotency_key'), 0, 64) : null,
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Submitted (:ref).', ['ref' => $submission->reference])]);

        return to_route('workspaces.forms.submissions', [$workspace, $form]);
    }

    public function submissions(Request $request, Workspace $workspace, Form $form, ReportExporter $exporter): Response|BaseResponse
    {
        Gate::authorize('contribute', $workspace);
        $this->own($workspace, $form);
        $rows = FormSubmission::query()->where('form_id', $form->id)->with(['submitter:id,name', 'task:id,workspace_id,number,title', 'version:id,version,schema'])
            ->latest('id')->limit(500)->get();
        $schema = $form->currentVersion !== null ? $form->currentVersion->schema : $form->draft;
        $fields = collect(FormSchema::fields($schema))->reject(fn (array $f) => $f['type'] === 'file')->values();

        $format = (string) $request->query('format', '');
        if (in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            $columns = [
                ['key' => 'reference', 'label' => 'Reference', 'type' => 'text'],
                ['key' => 'date', 'label' => 'Submitted', 'type' => 'date'],
                ['key' => 'by', 'label' => 'By', 'type' => 'text'],
                ...$fields->map(fn ($f) => ['key' => 'f_'.$f['key'], 'label' => (string) $f['label'], 'type' => $f['type'] === 'number' || $f['type'] === 'rating' ? 'number' : 'text', 'sum' => false])->all(),
            ];
            $data = $rows->map(fn (FormSubmission $s) => [
                'reference' => $s->reference, 'date' => $s->created_at?->toDateString(), 'by' => $s->submitter->name ?? $s->submitter_name ?? '',
                ...$fields->mapWithKeys(fn ($f) => ['f_'.$f['key'] => self::flat($s->answers[$f['key']] ?? null)])->all(),
            ])->values()->all();

            return $exporter->respond(new ExportTable($form->name.' submissions', ['Client' => $workspace->name, 'Version' => $form->currentVersion !== null ? (string) $form->currentVersion->version : '-'], $columns, $data), $format);
        }

        return Inertia::render('forms/Submissions', [
            'workspace' => $this->presenter->header($workspace, $request->user()),
            'form' => ['id' => $form->id, 'name' => $form->name, 'version' => $form->currentVersion?->version, 'can_fill' => $form->current_version_id !== null && ! $form->archived],
            'submissions' => $rows->map(function (FormSubmission $s) use ($workspace) {
                $labels = collect(FormSchema::fields($s->version?->schema))->pluck('label', 'key');

                return [
                    'id' => $s->id, 'reference' => $s->reference, 'version' => $s->version?->version, 'created_at' => $s->created_at?->toIso8601String(),
                    'by' => $s->submitter->name ?? $s->submitter_name ?? 'Unknown', 'public' => $s->submitted_by === null,
                    'answers' => collect($s->answers)->map(fn ($v, $k) => ['label' => (string) ($labels[$k] ?? $k), 'value' => self::flat($v)])->values(),
                    'task' => $s->task !== null ? ['title' => $s->task->title, 'url' => route('workspaces.tasks.show', [$workspace, $s->task], false)] : null,
                ];
            })->values(),
        ]);
    }

    /**
     * Files from the request grouped by field key (files[key][]).
     *
     * @return array<string, list<UploadedFile>>
     */
    public static function uploads(Request $request): array
    {
        $out = [];
        foreach ((array) $request->file('files', []) as $key => $list) {
            foreach (is_array($list) ? $list : [$list] as $file) {
                if ($file instanceof UploadedFile) {
                    $out[(string) $key][] = $file;
                }
            }
        }

        return $out;
    }

    private static function flat(mixed $value): string
    {
        return is_array($value) ? implode(', ', array_map('strval', $value)) : (string) ($value ?? '');
    }

    private function own(Workspace $workspace, Form $form): void
    {
        abort_unless($form->workspace_id === $workspace->id, 404);
    }
}
