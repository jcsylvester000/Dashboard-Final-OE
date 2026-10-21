<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Local development only: give the admin account a known password from .env
 * (DEV_ADMIN_EMAIL / DEV_ADMIN_PASSWORD) so testing doesn't need reset links.
 * Refuses to run in production. Re-run any time the password is forgotten.
 */
class DevAdmin extends Command
{
    /** @var string */
    protected $signature = 'dev:admin';

    /** @var string */
    protected $description = 'Set the local admin password from DEV_ADMIN_PASSWORD (local development only)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('dev:admin never runs in production.');

            return self::FAILURE;
        }

        $email = Str::lower((string) config('app.dev_admin.email'));
        $password = (string) config('app.dev_admin.password');
        if ($password === '') {
            $this->error('Set DEV_ADMIN_PASSWORD in .env first.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("No account {$email}. Run php artisan db:seed first.");

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => $password,
            'is_active' => true,
            'must_change_password' => false,
        ])->save();
        $user->accessLinks()->open()->update(['revoked_at' => now()]);

        $this->info("Done. Sign in at /login as {$email} with the password in DEV_ADMIN_PASSWORD.");

        return self::SUCCESS;
    }
}
