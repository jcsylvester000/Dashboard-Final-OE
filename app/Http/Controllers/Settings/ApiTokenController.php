<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Personal API tokens for the mobile app and integrations (P7).
 * The token text is shown once, right after it is created.
 */
class ApiTokenController extends Controller
{
    public const ABILITIES = ['read' => 'Read', 'write' => 'Read & write'];

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/ApiTokens', [
            'tokens' => $user->tokens()->latest('id')->get()->map(fn (PersonalAccessToken $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => $t->abilities,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ])->values(),
            'plainToken' => $request->session()->get('plainToken'),
            'maxDays' => (int) config('api.max_token_days', 365),
        ]);
    }

    public function store(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'access' => ['required', Rule::in(array_keys(self::ABILITIES))],
            'days' => ['required', 'integer', 'min:1', 'max:'.(int) config('api.max_token_days', 365)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $abilities = $data['access'] === 'write' ? ['read', 'write'] : ['read'];
        $token = $user->createToken((string) $data['name'], $abilities, now()->addDays((int) $data['days']));

        $activity->log('api.token-created', $user, ['name' => $data['name'], 'abilities' => $abilities], $user);

        return to_route('api-tokens.index')->with('plainToken', $token->plainTextToken);
    }

    public function destroy(Request $request, int $token, ActivityLogger $activity): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->tokens()->whereKey($token)->delete();
        $activity->log('api.token-revoked', $user, ['token_id' => $token], $user);

        return back();
    }
}
