<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Documents\DocumentMerger;
use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * E-52: generate a PDF for a project from a document template; the file lands in the
 * project's files.
 */
class DocumentTemplateController extends Controller
{
    public function generate(Request $request, Workspace $workspace, Project $project, DocumentMerger $merger, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('contribute', $workspace);
        abort_unless($project->workspace_id === $workspace->id, 404);
        $id = (int) $request->validate(['template_id' => ['required', 'integer', Rule::exists('document_templates', 'id')->where('archived', false)]])['template_id'];
        $template = DocumentTemplate::query()->findOrFail($id);
        /** @var User $user */
        $user = $request->user();
        $result = $merger->generate($template, $workspace, $project, $user);
        $activity->log('document.generated', $project, ['template' => $template->name, 'version' => $template->version, 'attachment_id' => $result['attachment_id']], $user);
        Inertia::flash('toast', $result['unknown'] === []
            ? ['type' => 'success', 'message' => sprintf('"%s" saved to the project\'s files.', $template->name)]
            : ['type' => 'warning', 'message' => sprintf('Saved, but these fields are unknown: %s.', implode(', ', $result['unknown']))]);

        return back();
    }
}
