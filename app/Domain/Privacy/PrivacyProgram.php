<?php

namespace App\Domain\Privacy;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Settings\AppSettings;
use App\Models\PrivacyAcknowledgement;
use App\Models\User;

/**
 * E-99: the staff privacy notice (versioned; everyone acknowledges each new version), the
 * DPO contact shown in the app footer, and the data retention schedule.
 */
class PrivacyProgram
{
    public const KEY = 'privacy';

    public function __construct(
        private readonly AppSettings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return array{version: int, title: string, body: string, published_at: string|null, dpo_name: string|null, dpo_email: string|null, dpo_phone: string|null}
     */
    public function get(): array
    {
        $s = (array) $this->settings->get(self::KEY, []);

        return [
            'version' => (int) ($s['version'] ?? 0),
            'title' => (string) ($s['title'] ?? 'Staff privacy notice'),
            'body' => (string) ($s['body'] ?? ''),
            'published_at' => isset($s['published_at']) ? (string) $s['published_at'] : null,
            'dpo_name' => isset($s['dpo_name']) && $s['dpo_name'] !== '' ? (string) $s['dpo_name'] : null,
            'dpo_email' => isset($s['dpo_email']) && $s['dpo_email'] !== '' ? (string) $s['dpo_email'] : null,
            'dpo_phone' => isset($s['dpo_phone']) && $s['dpo_phone'] !== '' ? (string) $s['dpo_phone'] : null,
        ];
    }

    /** Publishing a new version asks everyone to acknowledge it again. */
    public function publish(string $title, string $body, User $by): int
    {
        $current = $this->get();
        $version = $current['version'] + 1;
        $this->settings->set(self::KEY, [...$current, 'version' => $version, 'title' => $title, 'body' => $body, 'published_at' => now()->toIso8601String()]);
        $this->activity->log('privacy.notice-published', null, ['version' => $version], $by);

        return $version;
    }

    public function saveDpo(?string $name, ?string $email, ?string $phone, User $by): void
    {
        $this->settings->set(self::KEY, [...$this->get(), 'dpo_name' => $name, 'dpo_email' => $email, 'dpo_phone' => $phone]);
        $this->activity->log('privacy.dpo-updated', null, [], $by);
    }

    public function needsAcknowledgement(User $user): bool
    {
        $version = $this->get()['version'];

        return $version > 0 && ! PrivacyAcknowledgement::query()->where('user_id', $user->id)->where('version', $version)->exists();
    }

    public function acknowledge(User $user, int $version): void
    {
        PrivacyAcknowledgement::query()->firstOrCreate(['user_id' => $user->id, 'version' => $version], ['acknowledged_at' => now()]);
        $this->activity->log('privacy.notice-acknowledged', $user, ['version' => $version], $user);
    }

    /**
     * What the app keeps and for how long: each period is read from the setting that enforces it.
     *
     * @return list<array{data: string, examples: string, kept: string, how: string}>
     */
    public function retentionSchedule(): array
    {
        $months = (int) config('security.activity_retention_months');
        $billingMonths = (int) config('security.billing_activity_retention_months');
        $years = (int) config('billing.retention_years');
        $loginDays = (int) config('privacy.login_history_days');

        return [
            ['data' => 'Staff accounts', 'examples' => 'Name, work email, job title, departments, role',
                'kept' => 'While the person works here; after they leave, until an admin erases the account',
                'how' => 'Admin > Team members: deactivate, then "Erase personal data" (name and email replaced, sign-ins and tokens removed)'],
            ['data' => 'Sign-in history and devices', 'examples' => 'IP address, browser, sign-in times',
                'kept' => "{$loginDays} days", 'how' => 'privacy:prune-logins daily (PRIVACY_LOGIN_HISTORY_DAYS)'],
            ['data' => 'Activity log', 'examples' => 'Who did what and when',
                'kept' => "{$months} months online, then monthly archive files", 'how' => 'activity:archive daily (ACTIVITY_RETENTION_MONTHS)'],
            ['data' => 'Billing activity log', 'examples' => 'Invoice, payment and rate changes',
                'kept' => "{$billingMonths} months online, then monthly archive files", 'how' => 'activity:archive daily (BILLING_ACTIVITY_RETENTION_MONTHS)'],
            ['data' => 'Billing records', 'examples' => 'Invoices, credit notes, payments, client credit, stored PDFs, the names on them',
                'kept' => "{$years} years", 'how' => 'Never deleted by the app; kept when a person is erased (BILLING_RETENTION_YEARS)'],
            ['data' => 'Client work', 'examples' => 'Tasks, comments, time entries, task files, projects',
                'kept' => 'While the client workspace exists', 'how' => 'No automatic deletion; erased people show as "Former member"'],
            ['data' => 'Team file drive', 'examples' => 'Files people upload to My files',
                'kept' => 'Until the owner deletes them', 'how' => 'Unfinished uploads are cleared hourly (drive:prune)'],
            ['data' => 'Inbox alerts', 'examples' => 'In-app notifications',
                'kept' => '90 days once read or done; task alert records 180 days', 'how' => 'Pruned daily'],
            ['data' => 'Background job failures', 'examples' => 'Failed job payloads',
                'kept' => '30 days', 'how' => 'queue:prune-failed daily'],
            ['data' => 'API tokens', 'examples' => 'Personal access tokens',
                'kept' => 'Until revoked or expired; expired ones removed after 24 hours', 'how' => 'sanctum:prune-expired daily'],
            ['data' => 'Privacy notice acknowledgements', 'examples' => 'Who acknowledged which version, and when',
                'kept' => 'While the account exists', 'how' => 'Evidence that staff were informed'],
            ['data' => 'Privacy incident log', 'examples' => 'Incidents and breaches (kinds of data only)',
                'kept' => 'No automatic deletion', 'how' => 'Admins only; review with the DPO'],
        ];
    }
}
