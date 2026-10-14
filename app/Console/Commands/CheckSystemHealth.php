<?php

namespace App\Console\Commands;

use App\Domain\Identity\Permissions;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Domain\System\HealthChecks;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * E-96, every 5 minutes: when a check turns red, tell the people who watch system health
 * once; they hear again only after it recovers and fails again.
 */
class CheckSystemHealth extends Command
{
    /** @var string */
    protected $signature = 'health:check';

    /** @var string */
    protected $description = 'Run the system health checks and alert admins about new failures';

    public const STATE_KEY = 'health:failing';

    public function handle(HealthChecks $checks, Notifier $notifier): int
    {
        $failing = collect($checks->run())->where('status', 'fail')->keyBy('key');
        $before = (array) Cache::get(self::STATE_KEY, []);
        $new = $failing->keys()->diff($before);

        if ($new->isNotEmpty()) {
            $ids = User::query()->active()->get()->filter(fn (User $u) => $u->can(Permissions::SYSTEM_HEALTH))->pluck('id');
            $names = $new->map(fn ($k) => $failing[$k]['label'].': '.$failing[$k]['value'])->implode('; ');
            $notifier->sendPersonal($ids, NotificationKind::System, [
                'title' => __('System health: :n', ['n' => $names]),
                'body' => __('Open System health for details.'),
                'url' => route('admin.health', [], false),
            ]);
        }
        Cache::forever(self::STATE_KEY, $failing->keys()->values()->all());
        $this->info($failing->isEmpty() ? 'All checks pass.' : 'Failing: '.$failing->keys()->implode(', '));

        return self::SUCCESS;
    }
}
