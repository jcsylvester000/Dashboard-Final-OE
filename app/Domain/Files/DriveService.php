<?php

namespace App\Domain\Files;

use App\Domain\Files\UploadThing\UploadThingClient;
use App\Domain\Files\UploadThing\UploadThingException;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Models\Attachment;
use App\Models\DriveFile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * Team file drive ("My files"). Bytes live on UploadThing; this service owns the
 * rules: per-user quota (members 100 MB, admins 300 MB by default), allowed types,
 * sharing, locking and short-lived signed links.
 *
 * Upload flow: prepare() reserves quota and returns a presigned URL -> the browser
 * PUTs the file straight to UploadThing -> complete() confirms the bytes exist.
 */
class DriveService
{
    public const MB = 1024 * 1024;

    public function __construct(
        private readonly UploadThingClient $uploadThing,
        private readonly ActivityLogger $activity,
        private readonly Notifier $notifier,
    ) {}

    public function configured(): bool
    {
        return $this->uploadThing->configured();
    }

    public function isAdmin(User $user): bool
    {
        return $user->hasAnyRole((array) config('files.drive.admin_roles', ['super-admin', 'admin']));
    }

    public function quotaBytes(User $user): int
    {
        $mb = $this->isAdmin($user) ? config('files.drive.quota_mb.admin', 300) : config('files.drive.quota_mb.member', 100);

        return (int) $mb * self::MB;
    }

    /** Uploaded files plus uploads still in flight (they hold their space until they finish or expire). */
    public function usedBytes(User $user): int
    {
        return (int) DriveFile::query()
            ->where('owner_id', $user->id)
            ->where(fn ($q) => $q->where('status', DriveFile::READY)
                ->orWhere(fn ($q) => $q->where('status', DriveFile::PENDING)->where('created_at', '>', now()->subMinutes($this->pendingMinutes()))))
            ->sum('size');
    }

    /**
     * @return array{used: int, limit: int, free: int, is_admin: bool}
     */
    public function quota(User $user): array
    {
        $used = $this->usedBytes($user);
        $limit = $this->quotaBytes($user);

        return ['used' => $used, 'limit' => $limit, 'free' => max(0, $limit - $used), 'is_admin' => $this->isAdmin($user)];
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $ext = [];
        foreach ((array) config('files.drive.groups', []) as $group) {
            $ext = [...$ext, ...(array) config("files.groups.$group.ext", [])];
        }

        return array_values(array_unique($ext));
    }

    /**
     * Reserve quota and get a presigned upload URL.
     *
     * @return array{file: DriveFile, url: string}
     */
    public function prepare(User $user, string $name, int $size, ?string $type): array
    {
        $name = $this->cleanName($name);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, $this->allowedExtensions(), true)) {
            throw ValidationException::withMessages(['file' => __('That file type isn\'t allowed. Allowed: :list.', ['list' => implode(', ', $this->allowedExtensions())])]);
        }
        if ($size < 1) {
            throw ValidationException::withMessages(['file' => __('The file is empty.')]);
        }

        $mime = $this->mimeFor($ext, $type);

        $file = DB::transaction(function () use ($user, $name, $size, $mime) {
            // One upload reservation at a time per person, so two tabs can't overspend the quota.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $free = $this->quotaBytes($user) - $this->usedBytes($user);
            if ($size > $free) {
                throw ValidationException::withMessages(['file' => __('Not enough space: :size needed, :free left of your :limit.', [
                    'size' => self::human($size),
                    'free' => self::human(max(0, $free)),
                    'limit' => self::human($this->quotaBytes($user)),
                ])]);
            }

            return DriveFile::create([
                'owner_id' => $user->id,
                'name' => $name,
                'mime' => $mime,
                'size' => $size,
                'storage' => 'uploadthing',
                'custom_id' => 'oe-'.Str::lower((string) Str::ulid()),
                'status' => DriveFile::PENDING,
                'visibility' => 'private',
            ]);
        });

        try {
            $acl = config('services.uploadthing.acl');
            $presigned = $this->uploadThing->prepareUpload($name, $size, $mime, $file->custom_id, is_string($acl) && $acl !== '' ? $acl : null);
        } catch (UploadThingException $e) {
            $file->forceDelete();

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $file->forceFill(['storage_key' => $presigned['key']])->save();

        return ['file' => $file, 'url' => $presigned['url']];
    }

    /**
     * The browser says the upload finished: check UploadThing really has the file.
     */
    public function complete(DriveFile $file, User $by): DriveFile
    {
        if ($file->status === DriveFile::READY) {
            return $file;
        }
        if ($file->storage_key === null) {
            throw ValidationException::withMessages(['file' => __('This upload was never started.')]);
        }

        // The file can take a moment to appear after the PUT returns.
        $stat = ['exists' => false, 'size' => null, 'type' => null];
        foreach ([0, 400, 1200] as $waitMs) {
            if ($waitMs > 0 && ! app()->runningUnitTests()) {
                usleep($waitMs * 1000);
            }
            $stat = $this->uploadThing->stat($file->storage_key);
            if ($stat['exists']) {
                break;
            }
        }

        if (! $stat['exists']) {
            throw ValidationException::withMessages(['file' => __('UploadThing doesn\'t have this file yet. Try again in a moment.')]);
        }

        $file->forceFill([
            'status' => DriveFile::READY,
            'uploaded_at' => now(),
            // Trust UploadThing's byte count when it reports one (the signed URL already pins the size).
            'size' => $stat['size'] !== null && $stat['size'] > 0 ? $stat['size'] : $file->size,
        ])->save();

        $this->activity->log('drive.uploaded', $file, ['name' => $file->name, 'size' => $file->size], $by);

        return $file;
    }

    /** Drop an unfinished upload (frees its reserved quota). Finished files are left alone. */
    public function cancel(DriveFile $file): void
    {
        if ($file->status !== DriveFile::PENDING) {
            return;
        }
        if ($file->storage_key !== null) {
            try {
                $this->uploadThing->deleteFiles([$file->storage_key]);
            } catch (UploadThingException) {
                // Nothing was stored, or it will be pruned later.
            }
        }
        $file->forceDelete();
    }

    /** Short-lived signed link to the bytes (cached a little under its lifetime). */
    public function openUrl(DriveFile $file): string
    {
        abort_if($file->status !== DriveFile::READY || $file->storage_key === null, 404);
        $seconds = (int) config('files.drive.link_seconds', 300);
        $key = (string) $file->storage_key;

        return Cache::remember('drive:url:'.$file->id, max(10, $seconds - 30), fn () => $this->uploadThing->fileUrl($key, $seconds));
    }

    /**
     * D-06: on the free UploadThing plan files are public-read, so a URL handed to a
     * browser keeps working forever. In that mode the app streams the bytes itself and
     * the permanent URL never leaves the server; revoking access really revokes it.
     */
    public function publicByUrl(): bool
    {
        return config('services.uploadthing.acl') !== 'private';
    }

    /** Open a file: a short-lived link when files are private, otherwise streamed through the app. */
    public function deliver(DriveFile $file, bool $download = false): StreamedResponse|RedirectResponse
    {
        if (! $this->publicByUrl()) {
            return redirect()->away($this->openUrl($file));
        }

        $response = Http::withOptions(['stream' => true])->timeout(120)->get($this->openUrl($file));
        if ($response->failed()) {
            throw new UploadThingException('The file is temporarily unavailable.', $response->status());
        }
        $body = $response->toPsrResponse()->getBody();
        $fallback = Str::ascii($file->name) !== '' ? (string) preg_replace('/[^A-Za-z0-9 ._-]/', '', Str::ascii($file->name)) : 'file';

        return new StreamedResponse(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(262144);
                flush();
            }
        }, 200, [
            'Content-Type' => $file->mime,
            'Content-Length' => (string) $file->size,
            'Content-Disposition' => HeaderUtils::makeDisposition($download ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE, $file->name, $fallback !== '' ? $fallback : 'file'),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Change who can open the file. "public" creates a share link (optionally expiring).
     */
    public function share(DriveFile $file, string $visibility, ?int $workspaceId, ?int $expiresInDays, User $by): DriveFile
    {
        $this->guardUnlocked($file);

        $wasVisibility = $file->visibility;
        $wasWorkspace = $file->workspace_id;
        $file->visibility = $visibility;
        $file->workspace_id = $visibility === 'workspace' ? $workspaceId : null;

        if ($visibility === 'public') {
            $file->share_token ??= Str::random(40);
            $file->share_expires_at = $expiresInDays !== null && $expiresInDays > 0 ? now()->addDays($expiresInDays) : null;
        } else {
            // Leaving "public" revokes the link; a new one is issued if it's made public again.
            $file->share_token = null;
            $file->share_expires_at = null;
        }
        $file->save();

        $this->activity->log('drive.shared', $file, ['visibility' => $visibility, 'workspace_id' => $file->workspace_id], $by);
        if ($file->status === DriveFile::READY && ($wasVisibility !== $visibility || $wasWorkspace !== $file->workspace_id)) {
            $this->alertNewReaders($file, $by);
        }

        return $file;
    }

    /**
     * R-05: tell the people who can now see the file. Client-team shares go to that
     * team; team-wide shares go to everyone (FileShared is digest by default).
     */
    private function alertNewReaders(DriveFile $file, User $by): void
    {
        $ids = match ($file->visibility) {
            'workspace' => DB::table('workspace_user')->where('workspace_id', $file->workspace_id)->pluck('user_id')->all(),
            'team' => User::query()->active()->pluck('id')->all(),
            default => [],
        };
        if ($ids === []) {
            return;
        }

        $this->notifier->sendPersonal($ids, NotificationKind::FileShared, [
            'title' => __(':name shared ":file" with you', ['name' => $by->name, 'file' => $file->name]),
            'body' => $file->visibility === 'team' ? __('Shared with the whole team') : __('Shared with your client team'),
            'url' => route('drive.index', ['tab' => 'shared'], false),
        ], $by);
    }

    /** Issue a fresh public link (the old one stops working). */
    public function regenerateLink(DriveFile $file, User $by): DriveFile
    {
        $this->guardUnlocked($file);
        abort_unless($file->visibility === 'public', 422, 'Only public files have a link.');

        $file->forceFill(['share_token' => Str::random(40)])->save();
        $this->activity->log('drive.link-reset', $file, [], $by);

        return $file;
    }

    public function rename(DriveFile $file, string $name, User $by): DriveFile
    {
        $this->guardUnlocked($file);
        $ext = pathinfo($file->name, PATHINFO_EXTENSION);
        $clean = $this->cleanName($name);
        if (strtolower(pathinfo($clean, PATHINFO_EXTENSION)) !== strtolower($ext)) {
            $clean = pathinfo($clean, PATHINFO_FILENAME).'.'.$ext;
        }
        $file->forceFill(['name' => $clean])->save();

        return $file;
    }

    public function lock(DriveFile $file, bool $locked, User $by): DriveFile
    {
        $file->forceFill([
            'locked_at' => $locked ? now() : null,
            'locked_by' => $locked ? $by->id : null,
        ])->save();
        $this->activity->log($locked ? 'drive.locked' : 'drive.unlocked', $file, [], $by);

        return $file;
    }

    public function delete(DriveFile $file, User $by): void
    {
        $this->guardUnlocked($file);

        $uses = Attachment::query()->where('drive_file_id', $file->id)->count();
        if ($uses > 0) {
            throw ValidationException::withMessages(['file' => trans_choice('This file is attached to :count task or project. Remove it there first.|This file is attached to :count tasks or projects. Remove it there first.', $uses)]);
        }

        if ($file->storage_key !== null) {
            try {
                $this->uploadThing->deleteFiles([$file->storage_key]);
            } catch (UploadThingException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }
        }
        Cache::forget('drive:url:'.$file->id);
        $file->delete();
        $this->activity->log('drive.deleted', $file, ['name' => $file->name], $by);
    }

    /**
     * Abandoned uploads (browser closed mid-way): free their quota and the storage.
     */
    public function pruneStale(): int
    {
        $stale = DriveFile::query()
            ->where('status', DriveFile::PENDING)
            ->where('created_at', '<', now()->subMinutes($this->pendingMinutes()))
            ->limit(500)
            ->get();

        $keys = $stale->pluck('storage_key')->filter()->values()->all();
        if ($keys !== [] && $this->configured()) {
            try {
                $this->uploadThing->deleteFiles(array_map('strval', $keys));
            } catch (UploadThingException) {
                // Leave the rows for the next run.
                return 0;
            }
        }
        $stale->each->forceDelete();

        return $stale->count();
    }

    public static function human(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 MB';
        }
        if ($bytes >= self::MB) {
            return rtrim(rtrim(number_format($bytes / self::MB, 1), '0'), '.').' MB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }

    private function guardUnlocked(DriveFile $file): void
    {
        if ($file->isLocked()) {
            throw ValidationException::withMessages(['file' => __('This file is locked. Unlock it first.')]);
        }
    }

    private function pendingMinutes(): int
    {
        return (int) config('files.drive.pending_minutes', 60);
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\pL\pN ._()\-]+/u', '', $name);
        $name = trim((string) preg_replace('/\s+/', ' ', $name), ' .');

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = Str::limit(pathinfo($name, PATHINFO_FILENAME), 180, '');

        return ($base !== '' ? $base : 'file').($ext !== '' ? '.'.strtolower($ext) : '');
    }

    /** Use the browser's type only when it matches the extension. */
    private function mimeFor(string $ext, ?string $type): string
    {
        $known = (new MimeTypes)->getMimeTypes($ext);
        if ($type !== null && in_array(strtolower($type), $known, true)) {
            return strtolower($type);
        }

        return $known[0] ?? 'application/octet-stream';
    }
}
