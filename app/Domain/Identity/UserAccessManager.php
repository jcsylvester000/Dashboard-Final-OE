<?php

namespace App\Domain\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Account-level admin actions: deactivate, reactivate, sign out everywhere,
 * reset 2FA, temporary password.
 */
class UserAccessManager
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function deactivate(User $user, User $by): void
    {
        DB::transaction(function () use ($user) {
            $user->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
            $user->accessLinks()->open()->update(['revoked_at' => now()]);
            $this->signOutEverywhere($user, log: false);
        });

        $this->activity->log('user.deactivated', $user, actor: $by);
    }

    public function reactivate(User $user, User $by): void
    {
        $user->forceFill(['is_active' => true, 'deactivated_at' => null])->save();

        $this->activity->log('user.reactivated', $user, actor: $by);
    }

    /**
     * Ends all browser sessions and "remember me" cookies for the user.
     * API tokens (Sanctum, P7) will be revoked here too.
     */
    public function signOutEverywhere(User $user, ?User $by = null, bool $log = true): void
    {
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if ($log) {
            $this->activity->log('user.sessions-revoked', $user, actor: $by);
        }
    }

    public function resetTwoFactor(User $user, User $by): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->activity->log('user.two-factor-reset', $user, actor: $by);
    }

    /**
     * Sets a random temporary password the admin shares manually.
     * The user must change it at next login.
     */
    public function issueTemporaryPassword(User $user, User $by): string
    {
        $password = self::generatePassword();

        $user->forceFill(['password' => $password, 'must_change_password' => true])->save();
        $this->signOutEverywhere($user, log: false);

        $this->activity->log('user.temporary-password-issued', $user, actor: $by);

        return $password;
    }

    public static function generatePassword(): string
    {
        // 4 groups of 4 from an unambiguous alphabet, plus guaranteed classes.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }

        return implode('-', $groups).'-A'.random_int(2, 9).'z!';
    }
}
