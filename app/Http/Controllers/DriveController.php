<?php

namespace App\Http\Controllers;

use App\Domain\Files\DriveService;
use App\Domain\Files\UploadThing\UploadThingException;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\DriveFile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "My files": each team member's drive on UploadThing (drop zone, quota,
 * sharing, lock, public links for clients).
 */
class DriveController extends Controller
{
    public function __construct(private readonly DriveService $drive) {}

    public function index(Request $request, WorkspaceAccess $access): Response
    {
        /** @var User $user */
        $user = $request->user();
        $tab = $request->validate(['tab' => ['nullable', Rule::in(['mine', 'shared'])]])['tab'] ?? 'mine';

        $mine = DriveFile::query()
            ->with('workspace:id,name')
            ->where('owner_id', $user->id)
            ->ready()
            ->latest('id')
            ->limit(500)
            ->get();

        $workspaces = Workspace::query()->visibleTo($user)->orderBy('name')->get(['id', 'name', 'status'])
            ->filter(fn (Workspace $w) => $access->canView($user, $w));
        $workspaceIds = $workspaces->pluck('id')->all();

        // Files other people shared with the whole team or with a client team I'm on.
        $shared = DriveFile::query()
            ->with(['workspace:id,name', 'owner:id,name'])
            ->where('owner_id', '!=', $user->id)
            ->ready()
            ->where(fn (Builder $q) => $q->whereIn('visibility', ['team', 'public'])
                ->orWhere(fn (Builder $q) => $q->where('visibility', 'workspace')->whereIn('workspace_id', $workspaceIds)))
            ->latest('id')
            ->limit(300)
            ->get();

        return Inertia::render('files/Index', [
            'tab' => $tab,
            'configured' => $this->drive->configured(),
            'publicByUrl' => $this->drive->publicByUrl(),
            'quota' => $this->drive->quota($user),
            'allowed' => $this->drive->allowedExtensions(),
            'files' => $mine->map(fn (DriveFile $f) => $this->present($f, $user))->values(),
            'shared' => $shared->map(fn (DriveFile $f) => $this->present($f, $user))->values(),
            'workspaces' => $workspaces->map(fn (Workspace $w) => ['id' => $w->id, 'name' => $w->name])->values(),
            'visibilities' => DriveFile::VISIBILITIES,
        ]);
    }

    /** JSON for "Attach from My files": my ready files, newest first. */
    public function picker(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));

        $files = DriveFile::query()
            ->where('owner_id', $user->id)
            ->ready()
            ->when($q !== '', fn (Builder $b) => $b->where('name', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->latest('id')
            ->limit(50)
            ->get(['id', 'name', 'mime', 'size', 'uploaded_at', 'created_at']);

        return response()->json($files->map(fn (DriveFile $f) => [
            'id' => $f->id,
            'name' => $f->name,
            'size' => $f->size,
            'is_image' => $f->isImage(),
        ])->values());
    }

    /** Step 1 (JSON): reserve quota, get a presigned UploadThing URL. */
    public function prepare(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.(1024 * DriveService::MB)],
            'type' => ['nullable', 'string', 'max:150'],
        ]);

        if (! $this->drive->configured()) {
            return response()->json(['message' => 'File storage is not configured (UPLOADTHING_TOKEN).'], 503);
        }

        ['file' => $file, 'url' => $url] = $this->drive->prepare($user, $data['name'], (int) $data['size'], $data['type'] ?? null);

        return response()->json(['id' => $file->id, 'url' => $url, 'name' => $file->name]);
    }

    /** Step 3 (JSON): the browser finished the PUT; confirm and mark ready. */
    public function complete(Request $request, DriveFile $file): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($file->owner_id === $user->id, 403);

        $this->drive->complete($file, $user);

        return response()->json(['id' => $file->id, 'status' => $file->status]);
    }

    /** The browser's upload failed: release the reserved space right away. */
    public function cancel(Request $request, DriveFile $file): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($file->owner_id === $user->id, 403);
        $this->drive->cancel($file);

        return response()->json(['ok' => true]);
    }

    /** Open / download: a short-lived signed link, or streamed when files are public-by-URL (D-06). */
    public function open(Request $request, DriveFile $file): RedirectResponse|StreamedResponse
    {
        Gate::authorize('view', $file);

        try {
            return $this->drive->deliver($file, $request->boolean('download'));
        } catch (UploadThingException $e) {
            abort(502, $e->getMessage());
        }
    }

    public function update(Request $request, DriveFile $file, WorkspaceAccess $access): RedirectResponse
    {
        Gate::authorize('manage', $file);
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'visibility' => ['sometimes', Rule::in(array_keys(DriveFile::VISIBILITIES))],
            'workspace_id' => ['nullable', 'required_if:visibility,workspace', 'integer', Rule::exists('workspaces', 'id')],
            'expires_in_days' => ['nullable', 'integer', Rule::in([0, 1, 7, 30, 90])],
        ]);

        if (isset($data['name'])) {
            $this->drive->rename($file, $data['name'], $user);
        }

        if (isset($data['visibility'])) {
            $workspaceId = isset($data['workspace_id']) ? (int) $data['workspace_id'] : null;
            if ($workspaceId !== null) {
                $ws = Workspace::query()->findOrFail($workspaceId);
                abort_unless($access->canView($user, $ws), 403);
            }
            $this->drive->share($file, $data['visibility'], $workspaceId, isset($data['expires_in_days']) ? (int) $data['expires_in_days'] : null, $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    public function regenerateLink(Request $request, DriveFile $file): RedirectResponse
    {
        Gate::authorize('manage', $file);
        /** @var User $user */
        $user = $request->user();
        $this->drive->regenerateLink($file, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('New link created. The old link no longer works.')]);

        return back();
    }

    public function lock(Request $request, DriveFile $file): RedirectResponse
    {
        Gate::authorize('manage', $file);
        /** @var User $user */
        $user = $request->user();
        $locked = $request->boolean('locked', true);
        $this->drive->lock($file, $locked, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $locked ? __('Locked. It can\'t be deleted, renamed or re-shared until unlocked.') : __('Unlocked.')]);

        return back();
    }

    public function destroy(Request $request, DriveFile $file): RedirectResponse
    {
        Gate::authorize('manage', $file);
        /** @var User $user */
        $user = $request->user();
        $this->drive->delete($file, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deleted.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DriveFile $f, User $user): array
    {
        $canManage = Gate::forUser($user)->allows('manage', $f);

        return [
            'id' => $f->id,
            'name' => $f->name,
            'mime' => $f->mime,
            'size' => $f->size,
            'is_image' => $f->isImage(),
            'visibility' => $f->visibility,
            'workspace' => $f->workspace?->only(['id', 'name']),
            'owner' => $f->relationLoaded('owner') ? $f->owner->name : null,
            'locked' => $f->isLocked(),
            'share_url' => $canManage && $f->shareIsLive() ? route('share.show', $f->share_token) : null,
            'share_expires_at' => $f->share_expires_at?->toIso8601String(),
            'share_expired' => $f->visibility === 'public' && $f->share_expires_at !== null && $f->share_expires_at->isPast(),
            'share_downloads' => $f->share_downloads,
            'open_url' => route('drive.open', $f),
            'uploaded_at' => ($f->uploaded_at ?? $f->created_at)?->toIso8601String(),
            'can_manage' => $canManage,
        ];
    }
}
