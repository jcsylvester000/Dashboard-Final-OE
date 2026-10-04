<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The token's owner, the abilities of the token and the unread alert count.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var HasAbilities|null $token PersonalAccessToken for bearer tokens, TransientToken otherwise */
        $token = $user->currentAccessToken();

        return response()->json([
            'data' => [
                ...(new UserResource($user))->toArray($request),
                'abilities' => $token instanceof PersonalAccessToken ? (array) $token->abilities : [],
                'unread_notifications' => InboxNotification::query()->forUser($user)->bell()->count(),
            ],
        ]);
    }
}
