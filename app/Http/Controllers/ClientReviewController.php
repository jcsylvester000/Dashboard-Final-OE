<?php

namespace App\Http\Controllers;

use App\Domain\Files\ClientReview;
use App\Domain\Files\FileStore;
use App\Domain\Files\UploadThing\UploadThingClient;
use App\Domain\Settings\CompanyProfile;
use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * E-08: the client's review page for one file version (no login). Token only, throttled,
 * noindex / no-referrer; unknown, revoked or expired links are a plain 404. The client
 * gives their name once (kept in this browser session) before commenting or deciding.
 */
class ClientReviewController extends Controller
{
    public function __construct(
        private readonly ClientReview $review,
        private readonly CompanyProfile $company,
    ) {}

    public function show(Request $request, string $token): View
    {
        $attachment = $this->attachment($token);
        Attachment::query()->whereKey($attachment->id)->update(['review_views' => DB::raw('review_views + 1')]);
        $company = $this->company->get();

        return view('client.review', [
            'attachment' => $attachment,
            'token' => $token,
            'name' => $this->clientName($request, $token),
            'threads' => $this->review->threads($attachment, (string) $company['name']),
            'agency' => $company['name'],
            'brand' => $company['brand_color'],
            'logo' => $this->company->logoDataUri(),
        ]);
    }

    public function file(string $token, FileStore $store, UploadThingClient $uploadThing): Response
    {
        $attachment = $this->attachment($token);
        if ($attachment->disk === 'uploadthing') {
            return redirect()->away($uploadThing->fileUrl($attachment->key, (int) config('files.drive.link_seconds', 300)));
        }
        abort_unless($store->exists($attachment->key), 404);

        return $store->response($attachment->key, $attachment->original_name, $attachment->mime, $attachment->previewable());
    }

    private function clientName(Request $request, string $token): ?string
    {
        $name = $request->session()->get('client_review.'.$token);

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function setName(Request $request, string $token): RedirectResponse
    {
        $this->attachment($token);
        $name = trim((string) $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']])['name']);
        $request->session()->put('client_review.'.$token, strip_tags($name));

        return back();
    }

    public function comment(Request $request, string $token): RedirectResponse
    {
        $attachment = $this->attachment($token);
        $name = $this->clientName($request, $token);
        abort_if($name === null, 403);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'x' => ['nullable', 'numeric', 'between:0,1'],
            'y' => ['nullable', 'numeric', 'between:0,1', 'required_with:x'],
            'parent_id' => ['nullable', 'integer'],
        ]);
        $this->review->comment($attachment, $name, trim((string) $data['body']),
            isset($data['x']) ? (float) $data['x'] : null, isset($data['y']) ? (float) $data['y'] : null,
            isset($data['parent_id']) ? (int) $data['parent_id'] : null);

        return back()->with('client_review_status', __('Thanks, your comment was sent.'));
    }

    public function decide(Request $request, string $token): RedirectResponse
    {
        $attachment = $this->attachment($token);
        $name = $this->clientName($request, $token);
        abort_if($name === null, 403);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,changes'],
            'note' => ['nullable', 'required_if:decision,changes', 'string', 'max:2000'],
        ]);
        $this->review->decide($attachment, $name, (string) $data['decision'], isset($data['note']) ? trim((string) $data['note']) : null);

        return back()->with('client_review_status', $data['decision'] === 'approve' ? __('Approved. Thank you!') : __('Thanks, we will make the changes.'));
    }

    private function attachment(string $token): Attachment
    {
        $attachment = $this->review->find($token);
        abort_if($attachment === null, 404);

        return $attachment;
    }
}
