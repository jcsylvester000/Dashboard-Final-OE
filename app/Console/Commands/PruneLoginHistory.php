<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * E-99: sign-in history and devices (IP address, browser) are personal data; keep them only
 * PRIVACY_LOGIN_HISTORY_DAYS (default 180), as listed on the Privacy page.
 */
class PruneLoginHistory extends Command
{
    /** @var string */
    protected $signature = 'privacy:prune-logins';

    /** @var string */
    protected $description = 'Delete sign-in history older than the privacy retention period';

    public function handle(): int
    {
        $days = (int) config('privacy.login_history_days');
        $deleted = DB::table('user_sessions')->where('last_seen_at', '<', now()->subDays($days))->delete();
        $this->info("Removed {$deleted} sign-in record(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
