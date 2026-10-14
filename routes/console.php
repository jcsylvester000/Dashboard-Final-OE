<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduler (P4). On Forge add the scheduler (php artisan schedule:run every minute);
| locally `composer dev` runs schedule:work for you.
*/
Schedule::command('work:alerts')->hourly()->withoutOverlapping();

// Keep the inbox light: drop finished alerts after 90 days.
Schedule::call(function () {
    DB::table('notifications')
        ->where('created_at', '<', now()->subDays(90))
        ->where(fn ($q) => $q->whereNotNull('done_at')->orWhereNotNull('read_at'))
        ->delete();
    DB::table('task_alerts')->where('created_at', '<', now()->subDays(180))->delete();
})->daily()->name('prune-notifications')->withoutOverlapping();

Schedule::command('queue:prune-failed --hours=720')->daily();

// E-99: sign-in history and devices (IP, browser) are kept PRIVACY_LOGIN_HISTORY_DAYS.
Schedule::command('privacy:prune-logins')->dailyAt('03:00')->withoutOverlapping();

// Weekly report snapshots for workspace leads (P5).
Schedule::command('reports:snapshot')->weeklyOn(1, '06:00')->withoutOverlapping();

// Overdue client invoices -> Finance inbox (P6).
Schedule::command('billing:alerts')->dailyAt('07:00')->withoutOverlapping();
// E-73: open the current period per client and build retainer drafts for ended periods.
// E-91 / E-93 / E-95 / E-96: admin housekeeping.
Schedule::command('activity:archive')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('users:expire-access')->dailyAt('06:10')->timezone('Asia/Manila')->withoutOverlapping();
Schedule::command('holidays:remind')->monthlyOn(1, '09:00')->timezone('Asia/Manila');
Schedule::command('health:check')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(fn () => Cache::put('health:scheduler-heartbeat', now()->toIso8601String(), now()->addDay()))->everyMinute()->name('health-heartbeat');
Schedule::command('budgets:check')->hourlyAt(20)->withoutOverlapping(); // E-76
Schedule::command('billing:periods')->dailyAt('06:00')->timezone('Asia/Manila')->withoutOverlapping();

// E-33: overdue reviews (reminder, then escalation); E-39: timesheet nudges (Manila time).
Schedule::command('work:review-reminders')->hourlyAt(15)->weekdays()->withoutOverlapping();
Schedule::command('time:nudges --friday')->weeklyOn(5, '16:00')->timezone('Asia/Manila');
Schedule::command('time:nudges --monday')->weeklyOn(1, '09:00')->timezone('Asia/Manila');

// Team file drive: free quota held by abandoned uploads.
Schedule::command('drive:prune')->hourly()->withoutOverlapping();

// API housekeeping (P7): expired tokens and old idempotency records.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::call(fn () => DB::table('api_idempotency_keys')->where('created_at', '<', now()->subDays(2))->delete())
    ->daily()->name('prune-idempotency-keys');
