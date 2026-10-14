<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * E-97: every personal API token in the agency, with owner, access, last use and expiry,
 * and a revoke button. Deactivating someone already revokes their tokens.
 */
class ApiTokenAdminController extends Controller
{
    public function index(): Response
    {
        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->latest('id')
            ->limit(500)
            ->get();
        $owners = User::query()->whereIn('id', $tokens->pluck('tokenable_id'))->get(['id', 'name', 'email', 'is_active'])->keyBy('id');

        return Inertia::render('admin/ApiTokens', [
            'tokens' => $tokens->map(function (PersonalAccessToken $t) use ($owners): array {
                $owner = $owners->get($t->tokenable_id);

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'owner' => $owner->name ?? 'Removed user',
                    'owner_email' => $owner?->email,
                    'owner_active' => (bool) ($owner->is_active ?? false),
                    'abilities' => $t->abilities,
                    'last_used_at' => $t->last_used_at?->toIso8601String(),
                    'expires_at' => $t->expires_at?->toIso8601String(),
                    'expired' => $t->expires_at !== null && $t->expires_at->isPast(),
                    'created_at' => $t->created_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    public function destroy(int $token, ActivityLogger $activity): RedirectResponse
    {
        $row = PersonalAccessToken::query()->findOrFail($token);
        $owner = User::query()->find($row->tokenable_id);
        $row->delete();
        $activity->log('api.token-revoked-by-admin', $owner, ['token_id' => $token, 'name' => $row->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked. It stops working immediately.')]);

        return back();
    }
}
