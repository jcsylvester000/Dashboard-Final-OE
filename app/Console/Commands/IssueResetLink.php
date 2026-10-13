<?php

namespace App\Console\Commands;

use App\Domain\Identity\AccessLinkService;
use App\Models\User;
use App\Models\UserAccessLink;
use Illuminate\Console\Command;

/**
 * Lost password without email: print a one-time reset link (24 h) for an account.
 *   php artisan user:reset-link admin@overeasy.local
 * Issuing a new link cancels the previous one. On Windows, pipe to the clipboard so a wrapped
 * console line can't truncate it:  php artisan user:reset-link admin@overeasy.local | Set-Clipboard
 */
class IssueResetLink extends Command
{
    /** @var string */
    protected $signature = 'user:reset-link {email : The account e-mail address}';

    /** @var string */
    protected $description = 'Print a one-time password reset link for an account (no email is sent)';

    public function handle(AccessLinkService $links): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }
        if (! $user->is_active) {
            $this->error('That account is deactivated. Reactivate it in Admin > Team members first.');

            return self::FAILURE;
        }

        $url = $links->issue($user, UserAccessLink::PURPOSE_RESET)['url'];
        // Only the URL on stdout, so `| Set-Clipboard` copies exactly the link.
        $this->line($url);

        return self::SUCCESS;
    }
}
