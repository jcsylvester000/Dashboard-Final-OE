<?php

namespace App\Domain\Identity;

use App\Models\User;
use App\Models\UserAccessLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and redeems one-time account setup / password reset links.
 * The app never sends email: the admin copies the link and shares it manually.
 */
class AccessLinkService
{
    /** Hours a link stays valid, by purpose. */
    public const TTL_HOURS = [
        UserAccessLink::PURPOSE_SETUP => 72,
        UserAccessLink::PURPOSE_RESET => 24,
    ];

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Revoke any open links for the user and issue a fresh one.
     *
     * @return array{url: string, link: UserAccessLink}
     */
    public function issue(User $user, string $purpose, ?User $issuedBy = null): array
    {
        abort_unless(array_key_exists($purpose, self::TTL_HOURS), 422, 'Unknown link purpose.');

        $token = Str::random(64);

        $link = DB::transaction(function () use ($user, $purpose, $issuedBy, $token) {
            $user->accessLinks()->open()->update(['revoked_at' => now()]);

            return $user->accessLinks()->create([
                'purpose' => $purpose,
                'token_hash' => self::hash($token),
                'expires_at' => now()->addHours(self::TTL_HOURS[$purpose]),
                'created_by' => $issuedBy?->getKey(),
            ]);
        });

        $this->activity->log('access-link.issued', $user, [
            'purpose' => $purpose,
            'expires_at' => $link->expires_at->toIso8601String(),
        ], $issuedBy);

        return ['url' => route('access-link.show', ['token' => $token]), 'link' => $link];
    }

    public function find(string $token): ?UserAccessLink
    {
        if (strlen($token) !== 64) {
            return null;
        }

        $link = UserAccessLink::with('user')->where('token_hash', self::hash($token))->first();

        return $link?->isUsable() && $link->user?->is_active ? $link : null;
    }

    /**
     * Set the new password, burn the link and sign the user out everywhere.
     */
    public function redeem(UserAccessLink $link, string $password): User
    {
        return DB::transaction(function () use ($link, $password) {
            /** @var UserAccessLink $locked */
            $locked = UserAccessLink::with('user')->whereKey($link->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->isUsable(), 410, 'This link has already been used or has expired.');

            $user = $locked->user;
            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'remember_token' => Str::random(60),
            ])->save();

            $locked->forceFill(['used_at' => now()])->save();

            DB::table('sessions')->where('user_id', $user->getKey())->delete();

            $this->activity->log('access-link.redeemed', $user, ['purpose' => $locked->purpose], $user);

            return $user;
        });
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
