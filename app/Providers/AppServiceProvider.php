<?php

namespace App\Providers;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Notifications\WorkEventSubscriber;
use App\Models\Comment;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureAuthAuditing();
        $this->configureNotifications();
    }

    protected function configureNotifications(): void
    {
        // Work events -> in-app alerts (P4). Never email.
        Event::subscribe(WorkEventSubscriber::class);

        // `composer dev` also runs the scheduler (hourly alerts) and, once installed, Reverb.
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('schedule:work', 'scheduler');
            if (class_exists('Laravel\\Reverb\\ReverbServiceProvider') && config('broadcasting.default') === 'reverb') {
                DevCommands::artisan('reverb:start', 'reverb');
            }
        }
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(app()->isProduction());

        // Catch N+1 lazy loading and silently discarded mass-assignment while developing.
        // Short, stable type names for polymorphic links/mentions/labels (used in URLs and the API).
        Relation::morphMap([
            'workspace' => Workspace::class,
            'project' => Project::class,
            'task' => Task::class,
            'comment' => Comment::class,
            'time_entry' => TimeEntry::class,
            'expense' => Expense::class,
        ]);

        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : Password::min(8),
        );
    }

    protected function configureAuthorization(): void
    {
        // Super Admin passes every Gate / policy check.
        Gate::before(fn (User $user): ?bool => $user->isSuperAdmin() ? true : null);
    }

    protected function configureAuthAuditing(): void
    {
        Event::listen(function (Login $event): void {
            // Deactivated accounts are refused by Fortify::authenticateUsing; passkey logins are
            // signed out by EnsureUserIsActive on the next request, so don't record them as logins.
            if ($event->user instanceof User && $event->user->is_active) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
                app(ActivityLogger::class)->log('auth.login', $event->user, actor: $event->user);
            }
        });

        Event::listen(function (Logout $event): void {
            if ($event->user instanceof User) {
                app(ActivityLogger::class)->log('auth.logout', $event->user, actor: $event->user);
            }
        });

        Event::listen(function (Failed $event): void {
            // Record the attempt without storing the password or confirming whether the email exists.
            app(ActivityLogger::class)->log('auth.failed', properties: [
                'email' => mb_strtolower((string) ($event->credentials['email'] ?? '')),
            ]);
        });
    }
}
