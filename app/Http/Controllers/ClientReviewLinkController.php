<?php

namespace App\Http\Controllers;

use App\Domain\Files\AttachmentPresenter;
use App\Domain\Files\ClientReview;
use App\Domain\Files\FeedbackSheet;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Attachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

/**
 * E-08 staff side: share or revoke a version's client review link (people who may approve
 * the file). E-09: the feedback sheet PDF for a version (anyone who can open the client).
 */
class ClientReviewLinkController extends Controller
{
    public function store(Request $request, Attachment $attachment, AttachmentPresenter $presenter, ClientReview $review): RedirectResponse
    {
        $user = $this->authorizeApprover($request, $attachment, $presenter);
        $days = (int) ($request->validate(['expires_days' => ['nullable', 'integer', 'in:7,14,30']])['expires_days'] ?? 14);
        $review->share($attachment, $days, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client review link ready. Copy it from the file and send it to the client.')]);

        return back();
    }

    public function destroy(Request $request, Attachment $attachment, AttachmentPresenter $presenter, ClientReview $review): RedirectResponse
    {
        $user = $this->authorizeApprover($request, $attachment, $presenter);
        $review->revoke($attachment, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client review link turned off.')]);

        return back();
    }

    public function feedbackSheet(Request $request, Attachment $attachment, WorkspaceAccess $access, FeedbackSheet $sheet): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($attachment->workspace_id);
        abort_unless($workspace !== null && $access->canView($user, $workspace), 403);
        abort_unless($attachment->isImage(), 404);

        return response($sheet->pdf($attachment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Feedback '.preg_replace('/[^A-Za-z0-9 ._-]/', '', pathinfo($attachment->original_name, PATHINFO_FILENAME)).' v'.$attachment->version.'.pdf"',
        ]);
    }

    private function authorizeApprover(Request $request, Attachment $attachment, AttachmentPresenter $presenter): User
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($attachment->workspace_id);
        $record = $attachment->loadMissing('attachable')->attachable;
        abort_unless($workspace !== null && $record !== null && $presenter->canApprove($record, $workspace, $user), 403);

        return $user;
    }
}
