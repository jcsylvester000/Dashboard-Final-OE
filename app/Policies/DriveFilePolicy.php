<?php

namespace App\Policies;

use App\Domain\Files\DriveService;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\DriveFile;
use App\Models\User;
use App\Models\Workspace;

/**
 * Drive files: the owner and admins manage them; others can open them
 * according to the file's visibility. Public links are handled separately
 * (ShareController) and never need a login.
 */
class DriveFilePolicy
{
    public function __construct(
        private readonly DriveService $drive,
        private readonly WorkspaceAccess $access,
    ) {}

    public function view(User $user, DriveFile $file): bool
    {
        if ($this->manage($user, $file)) {
            return true;
        }
        if ($file->status !== DriveFile::READY) {
            return false;
        }

        return match ($file->visibility) {
            'team', 'public' => true,
            'workspace' => $file->workspace_id !== null
                && ($ws = Workspace::query()->find($file->workspace_id)) !== null
                && $this->access->canView($user, $ws),
            default => false,
        };
    }

    /** Rename, share, delete (deleting also needs the file unlocked). */
    public function manage(User $user, DriveFile $file): bool
    {
        return $file->owner_id === $user->id || $this->drive->isAdmin($user);
    }
}
