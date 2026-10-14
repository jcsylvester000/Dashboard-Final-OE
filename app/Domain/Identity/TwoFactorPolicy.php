<?php

namespace App\Domain\Identity;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * E-87: people who can manage users, roles, money or the system must sign in with a
 * second factor (authenticator app or passkey). They get a grace period from the first
 * time they are seen without one; after it, every page leads to the security settings.
 */
class TwoFactorPolicy
{
    public function required(User $user): bool
    {
        if (! config('security.enforce_two_factor', true)) {
            return false;
        }
        foreach (Permissions::requiresTwoFactor() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function satisfied(User $user): bool
    {
        return $user->hasEnabledTwoFactorAuthentication() || $user->passkeys()->exists();
    }

    public function graceDays(): int
    {
        return max(0, (int) config('security.two_factor_grace_days', 7));
    }

    /**
     * Null when nothing is needed; otherwise the deadline (set on first sight).
     */
    public function deadline(User $user): ?CarbonImmutable
    {
        if (! $this->required($user) || $this->satisfied($user)) {
            if ($user->two_factor_deadline !== null && $this->satisfied($user)) {
                $user->forceFill(['two_factor_deadline' => null])->saveQuietly();
            }

            return null;
        }
        if ($user->two_factor_deadline === null) {
            $user->forceFill(['two_factor_deadline' => now()->addDays($this->graceDays())])->saveQuietly();
        }

        return CarbonImmutable::parse((string) $user->two_factor_deadline);
    }
}
