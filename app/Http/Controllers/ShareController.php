<?php

namespace App\Http\Controllers;

use App\Domain\Files\DriveService;
use App\Domain\Files\UploadThing\UploadThingException;
use App\Models\DriveFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public share links for clients (/s/{token}). No login. Only files marked
 * "Public link", uploaded, and not expired; anything else is a plain 404 so
 * links can't be probed.
 */
class ShareController extends Controller
{
    public function __construct(private readonly DriveService $drive) {}

    public function show(string $token): View
    {
        $file = $this->find($token);

        return view('share', [
            'file' => $file,
            'size' => DriveService::human($file->size),
            'from' => $file->owner->name,
            'fileUrl' => route('share.file', $token),
            'downloadUrl' => route('share.file', ['token' => $token, 'download' => 1]),
            'expires' => $file->share_expires_at,
        ]);
    }

    public function file(string $token): RedirectResponse|StreamedResponse
    {
        $file = $this->find($token);
        if (request()->boolean('download')) {
            $file->increment('share_downloads');
        }

        try {
            return $this->drive->deliver($file, request()->boolean('download'));
        } catch (UploadThingException) {
            abort(502, 'The file is temporarily unavailable. Please try again shortly.');
        }
    }

    private function find(string $token): DriveFile
    {
        abort_unless(strlen($token) === 40 && ctype_alnum($token), 404);

        $file = DriveFile::query()->with('owner:id,name')->where('share_token', $token)->first();
        abort_unless($file !== null && $file->shareIsLive(), 404);

        return $file;
    }
}
