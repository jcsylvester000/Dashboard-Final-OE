<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
