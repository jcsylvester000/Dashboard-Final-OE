<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\NotificationResource;
use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The token owner's in-app alerts (same inbox as the web app).
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $unreadOnly = $request->boolean('unread');

        return NotificationResource::collection(
            InboxNotification::query()->forUser($user)->active()
                ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
                ->latest()
                ->paginate(50)
        );
    }

    public function read(Request $request, string $notification): NotificationResource
    {
        /** @var User $user */
        $user = $request->user();
        $item = InboxNotification::query()->forUser($user)->whereKey($notification)->firstOrFail();
        if ($item->read_at === null) {
            $item->forceFill(['read_at' => now()])->save();
        }

        return new NotificationResource($item);
    }
}
