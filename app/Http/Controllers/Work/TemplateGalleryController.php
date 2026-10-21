<?php

namespace App\Http\Controllers\Work;

use App\Domain\Documents\DocumentMerger;
use App\Domain\Documents\ReportPacks;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Identity\Permissions;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Form;
use App\Models\ReportPack;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * E-55: one Templates area. Workflows and document templates are agency-wide (admins
 * manage them); forms and report packs belong to clients (their leads can copy them to
 * another client they lead). Every item can be duplicated and archived and shows how
 * often it is used.
 */
class TemplateGalleryController extends Controller
{
    public function __construct(
        private readonly WorkspaceAccess $access,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $led = Workspace::query()->visibleTo($user)->orderBy('name')->get(['id', 'name', 'slug'])->filter(fn (Workspace $w) => $this->access->canLead($user, $w))->values();
        $ledIds = $led->pluck('id')->all();
        $names = $led->pluck('name', 'id');

        return Inertia::render('templates/Gallery', [
            'canManage' => $user->can(Permissions::WORKSPACES_MANAGE),
            'workflows' => WorkflowTemplate::query()->withCount('steps')->orderBy('name')->get()->map(fn (WorkflowTemplate $t) => [
                'id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'active' => $t->is_active, 'uses' => $t->uses, 'steps' => (int) $t->getAttribute('steps_count'),
            ])->values(),
            'documents' => DocumentTemplate::query()->orderBy('archived')->orderBy('name')->get()->map(fn (DocumentTemplate $d) => [
                'id' => $d->id, 'name' => $d->name, 'kind' => $d->kind, 'body' => $d->body, 'version' => $d->version, 'archived' => $d->archived, 'uses' => $d->uses,
                'updated_at' => $d->updated_at?->toIso8601String(),
            ])->values(),
            'forms' => Form::query()->whereIn('workspace_id', $ledIds)->withCount('submissions')->orderBy('name')->get()->map(fn (Form $f) => [
                'id' => $f->id, 'name' => $f->name, 'client' => $names[$f->workspace_id] ?? '', 'archived' => $f->archived, 'uses' => (int) $f->getAttribute('submissions_count'),
                'published' => $f->current_version_id !== null,
            ])->values(),
            'packs' => ReportPack::query()->whereIn('workspace_id', $ledIds)->orderBy('name')->get()->map(fn (ReportPack $p) => [
                'id' => $p->id, 'name' => $p->name, 'client' => $names[$p->workspace_id] ?? '', 'enabled' => $p->enabled, 'uses' => $p->runs, 'frequency' => $p->frequency,
            ])->values(),
            'clients' => $led->map(fn (Workspace $w) => ['id' => $w->id, 'name' => $w->name, 'slug' => $w->slug])->values(),
            'documentKinds' => DocumentTemplate::KINDS,
            'mergeFields' => DocumentMerger::FIELDS,
        ]);
    }

    // Workflows (admins) -------------------------------------------------------

    public function duplicateWorkflow(Request $request, WorkflowTemplate $template): RedirectResponse
    {
        Gate::authorize(Permissions::WORKSPACES_MANAGE);
        DB::transaction(function () use ($template, $request) {
            $copy = $template->replicate(['slug', 'uses']);
            $copy->name = mb_substr($template->name.' (copy)', 0, 120);
            $copy->slug = $this->slug($template->name.' copy');
            $copy->created_by = $request->user()?->id;
            $copy->save();
            foreach ($template->steps as $step) {
                $copy->steps()->create($step->only(['position', 'department_id', 'title', 'description', 'offset_days', 'depends_on_previous', 'review_user_ids', 'review_leads', 'review_mode', 'review_sla_days', 'checklist']));
            }
        });

        return back();
    }

    public function archiveWorkflow(WorkflowTemplate $template): RedirectResponse
    {
        Gate::authorize(Permissions::WORKSPACES_MANAGE);
        $template->forceFill(['is_active' => ! $template->is_active])->save();

        return back();
    }

    // Document templates (admins) ------------------------------------------------

    public function storeDocument(Request $request): RedirectResponse
    {
        Gate::authorize(Permissions::WORKSPACES_MANAGE);
        $data = $this->validatedDocument($request);
        /** @var User $user */
        $user = $request->user();
        $doc = DocumentTemplate::query()->create([...$data, 'version' => 1, 'created_by' => $user->id]);
        $this->activity->log('document-template.created', null, ['id' => $doc->id, 'name' => $doc->name], $user);

        return back();
    }

    /** Saving keeps the previous text as a version. */
    public function updateDocument(Request $request, DocumentTemplate $document): RedirectResponse
    {
        Gate::authorize(Permissions::WORKSPACES_MANAGE);
        $data = $this->validatedDocument($request);
        /** @var User $user */
        $user = $request->user();
        DB::transaction(function () use ($document, $data, $user) {
            if ($data['body'] !== $document->body) {
                DocumentTemplateVersion::query()->create(['document_template_id' => $document->id, 'version' => $document->version, 'body' => $document->body, 'saved_by' => $user->id, 'created_at' => now()]);
                $data['version'] = $document->version + 1;
            }
            $document->forceFill($data)->save();
        });

        return back();
    }

    public function duplicateDocument(Request $request, DocumentTemplate $document): RedirectResponse
    {
        Gate::authorize(Permissions::WORKSPACES_MANAGE);
        DocumentTemplate::query()->create(['name' => mb_substr($document->name.' (copy)', 0, 120), 'kind' => $document->kind, 'body' => $document->body, 'version' => 1, 'created_by' => $request->user()?->id]);

        return back();
    }

    public function archiveDocument(DocumentTemplate $document): RedirectResponse
    {
        Gate::authorize(Permissions::WORKSPACES_MANAGE);
        $document->forceFill(['archived' => ! $document->archived])->save();

        return back();
    }

    // Client items (leads of both clients) ---------------------------------------

    public function duplicateForm(Request $request, Form $form): RedirectResponse
    {
        $target = $this->targetClient($request, $form->workspace_id);
        $source = $form->currentVersion !== null ? $form->currentVersion->schema : $form->draft;
        Form::query()->create([
            'workspace_id' => $target->id, 'name' => mb_substr($form->name.($target->id === $form->workspace_id ? ' (copy)' : ''), 0, 120),
            'description' => $form->description, 'draft' => $source,
            // Mappings to one client's custom fields may not exist on another: keep the shape, drop the map.
            'output' => [...$form->output, 'map' => $target->id === $form->workspace_id ? ($form->output['map'] ?? []) : [], 'notify_user_ids' => []],
            'created_by' => $request->user()?->id,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf('Copied to %s as a draft. Publish it there when ready.', $target->name)]);

        return back();
    }

    public function duplicatePack(Request $request, ReportPack $pack): RedirectResponse
    {
        $target = $this->targetClient($request, $pack->workspace_id);
        ReportPack::query()->create([
            'workspace_id' => $target->id, 'name' => $pack->name, 'sections' => $pack->sections, 'frequency' => $pack->frequency, 'day' => $pack->day,
            'recipient_ids' => [], 'enabled' => false, 'created_by' => $request->user()?->id,
            'next_run_on' => ReportPacks::nextRun($pack->frequency, $pack->day, CarbonImmutable::today())->toDateString(),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf('Copied to %s (off until you turn it on).', $target->name)]);

        return back();
    }

    private function targetClient(Request $request, int $sourceWorkspaceId): Workspace
    {
        /** @var User $user */
        $user = $request->user();
        $source = Workspace::query()->findOrFail($sourceWorkspaceId);
        abort_unless($this->access->canLead($user, $source), 403);
        $targetId = (int) $request->validate(['workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')]])['workspace_id'];
        $target = Workspace::query()->findOrFail($targetId);
        abort_unless($this->access->canLead($user, $target), 403);

        return $target;
    }

    /**
     * @return array{name: string, kind: string, body: string}
     */
    private function validatedDocument(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(DocumentTemplate::KINDS))],
            'body' => ['required', 'string', 'max:60000'],
        ]);

        return ['name' => (string) $data['name'], 'kind' => (string) $data['kind'], 'body' => (string) $data['body']];
    }

    private function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'template';
        $slug = $base;
        for ($n = 2; WorkflowTemplate::query()->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
