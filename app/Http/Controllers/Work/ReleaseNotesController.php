<?php

namespace App\Http\Controllers\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Settings\CompanyProfile;
use App\Domain\Text\RichText;
use App\Domain\Work\SprintService;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Models\Sprint;
use App\Models\User;
use App\Models\Workspace;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * E-27: a sprint's release notes. Drafted from Done tasks when the sprint closes; leads edit
 * them, download a PDF, or share a read-only link (no login, noindex, revocable).
 */
class ReleaseNotesController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly CompanyProfile $company,
    ) {}

    public function show(Request $request, Workspace $workspace, Sprint $sprint, WorkspacePresenter $presenter, RichText $rich): Response
    {
        Gate::authorize('view', $workspace);
        /** @var User $user */
        $user = $request->user();
        $notes = $sprint->release_notes ?? app(SprintService::class)->draftReleaseNotes($sprint);

        return Inertia::render('sprints/ReleaseNotes', [
            'workspace' => $presenter->header($workspace, $user),
            'sprint' => ['id' => $sprint->id, 'name' => $sprint->name, 'status' => $sprint->status, 'closed_at' => $sprint->closed_at?->toIso8601String()],
            'notes' => $notes,
            'html' => $rich->render($notes),
            'saved' => $sprint->release_notes !== null,
            'shareUrl' => $sprint->release_notes_token !== null ? route('release-notes.shared', $sprint->release_notes_token) : null,
            'canEdit' => Gate::allows('lead', $workspace),
        ]);
    }

    public function update(Request $request, Workspace $workspace, Sprint $sprint): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $notes = (string) $request->validate(['notes' => ['required', 'string', 'max:50000']])['notes'];
        $sprint->forceFill(['release_notes' => $notes])->save();
        $this->activity->log('sprint.release-notes-saved', $sprint);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Release notes saved.')]);

        return back();
    }

    /** Draft again from the Done tasks (replaces the text). */
    public function redraft(Workspace $workspace, Sprint $sprint, SprintService $sprints): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $sprint->forceFill(['release_notes' => $sprints->draftReleaseNotes($sprint)])->save();

        return back();
    }

    public function pdf(Workspace $workspace, Sprint $sprint, RichText $rich): HttpResponse
    {
        Gate::authorize('view', $workspace);
        $notes = $sprint->release_notes ?? app(SprintService::class)->draftReleaseNotes($sprint);
        $pdf = Pdf::loadView('pdf.release-notes', [
            'sprint' => $sprint, 'workspace' => $workspace, 'html' => $rich->render($notes),
            'company' => $this->company->get(), 'logo' => $this->company->logoDataUri(),
        ]);
        $pdf->setPaper('a4');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.Str::slug($sprint->name).'-release-notes.pdf"',
        ]);
    }

    public function share(Workspace $workspace, Sprint $sprint): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $sprint->forceFill(['release_notes_token' => Str::random(40), 'release_notes' => $sprint->release_notes ?? app(SprintService::class)->draftReleaseNotes($sprint)])->save();
        $this->activity->log('sprint.release-notes-shared', $sprint);

        return back();
    }

    public function unshare(Workspace $workspace, Sprint $sprint): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $sprint->forceFill(['release_notes_token' => null])->save();

        return back();
    }

    /** Public read-only page (no login). */
    public function shared(string $token, RichText $rich): View
    {
        abort_unless(strlen($token) === 40 && ctype_alnum($token), 404);
        $sprint = Sprint::query()->where('release_notes_token', $token)->first();
        abort_if($sprint === null || $sprint->release_notes === null || ! Workspace::query()->whereKey($sprint->workspace_id)->exists(), 404);
        $company = $this->company->get();

        return view('client.release-notes', [
            'sprint' => $sprint,
            'html' => $rich->render($sprint->release_notes),
            'agency' => $company['name'],
            'brand' => $company['brand_color'],
            'logo' => $this->company->logoDataUri(),
        ]);
    }
}
