<?php

namespace App\Domain\System;

use App\Models\DriveFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * E-96: the checks behind Admin > System health. Each is cheap (no file walks) so the
 * page loads fast. Status: ok, warn or fail.
 */
class HealthChecks
{
    public const HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    public const BACKUP_KEY = 'health:last-backup';

    /** Scheduler counts as stopped after this many minutes without a heartbeat. */
    public const SCHEDULER_STALE_MINUTES = 5;

    /**
     * @return list<array{key: string, label: string, status: string, value: string, detail: string|null}>
     */
    public function run(): array
    {
        return [
            $this->scheduler(),
            $this->queue(),
            $this->failedJobs(),
            $this->disk(),
            $this->drive(),
            $this->backup(),
            $this->opcache(),
            $this->video(),
            $this->version(),
        ];
    }

    /**
     * @return array{key: string, label: string, status: string, value: string, detail: string|null}
     */
    private function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);
        if (! is_string($beat)) {
            return $this->row('scheduler', 'Scheduler', 'fail', 'No heartbeat', 'Run the scheduler every minute (php artisan schedule:run; Forge: Scheduler). Locally composer dev runs it.');
        }
        $age = (int) CarbonImmutable::parse($beat)->diffInMinutes(now(), true);

        return $this->row('scheduler', 'Scheduler', $age > self::SCHEDULER_STALE_MINUTES ? 'fail' : 'ok',
            $age === 0 ? 'Ran this minute' : "Last ran {$age} min ago", 'Reminders, alerts, billing periods and archives depend on it.');
    }

    /**
     * @return array{key: string, label: string, status: string, value: string, detail: string|null}
     */
    private function queue(): array
    {
        try {
            $size = Queue::size();
        } catch (\Throwable) {
            return $this->row('queue', 'Background queue', 'fail', 'Unreachable', 'The queue connection ('.config('queue.default').') did not answer.');
        }
        $oldest = null;
        if (config('queue.default') === 'database') {
            $first = DB::table('jobs')->min('created_at');
            $oldest = $first !== null ? (int) floor((time() - (int) $first) / 60) : null;
        }
        $status = match (true) {
            $size > 1000 || ($oldest !== null && $oldest > 30) => 'fail',
            $size > 100 || ($oldest !== null && $oldest > 10) => 'warn',
            default => 'ok',
        };

        return $this->row('queue', 'Background queue', $status, "{$size} waiting".($oldest !== null && $size > 0 ? ", oldest {$oldest} min" : ''),
            'Connection: '.config('queue.default').'. A growing backlog means the worker is stopped or too slow.');
    }

    /**
     * @return array{key: string, label: string, status: string, value: string, detail: string|null}
     */
    private function failedJobs(): array
    {
        $day = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $all = DB::table('failed_jobs')->count();

        return $this->row('failed_jobs', 'Failed jobs', $day > 0 ? 'warn' : 'ok', "{$day} in the last 24 h ({$all} kept)", 'Retry or discard them on the Failed jobs page.');
    }

    /**
     * @return array{key: string, label: string, status: string, value: string, detail: string|null}
     */
    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($free === false || $total === false || $total <= 0) {
            return $this->row('disk', 'Disk space', 'warn', 'Unknown', null);
        }
        $pct = (int) round($free / $total * 100);

        return $this->row('disk', 'Disk space', $pct < 5 ? 'fail' : ($pct < 15 ? 'warn' : 'ok'),
            self::bytes((int) $free).' free of '.self::bytes((int) $total)." ({$pct}%)", 'Invoices, receipts and archives are stored here.');
    }

    /**
     * @return array{key: string, label: string, status: string, value: string, detail: string|null}
     */
    private function drive(): array
    {
        $bytes = (int) DriveFile::query()->sum('size');
        $count = DriveFile::query()->count();

        return $this->row('drive', 'File drive (UploadThing)', 'ok', self::bytes($bytes)." in {$count} files", 'Compare with your UploadThing plan limit.');
    }

    /**
     * @return array{key: string, label: string, status: string, value: string, detail: string|null}
     */
    private function backup(): array
    {
        $at = Cache::get(self::BACKUP_KEY);
        if (! is_string($at)) {
            return $this->row('backup', 'Last backup', 'warn', 'Not reported', 'Have the backup script run "php artisan health:backup-ok" after each successful backup.');
        }
        $hours = (int) CarbonImmutable::parse($at)->diffInHours(now(), true);

        return $this->row('backup', 'Last backup', $hours > 36 ? 'fail' : 'ok', CarbonImmutable::parse($at)->format('M j, H:i')." ({$hours} h ago)", null);
    }

    /**
     * @return array{key: string, label: string, status: string, value: string, detail: string|null}
     */
    private function opcache(): array
    {
        // E-115: production should run with OPcache on and a hit rate above 99 % once warm.
        $production = app()->isProduction();
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        if (! is_array($status) || ! ($status['opcache_enabled'] ?? false)) {
            return $this->row('opcache', 'PHP OPcache', $production ? 'warn' : 'ok', 'Off',
                $production ? 'Turn on opcache.enable (see the Runbook: PHP runtime tuning).' : 'Fine locally; required in production.');
        }
        $stats = (array) ($status['opcache_statistics'] ?? []);
        $rate = (float) ($stats['opcache_hit_rate'] ?? 0);
        $memory = (array) ($status['memory_usage'] ?? []);
        $free = (int) ($memory['free_memory'] ?? 0);

        return $this->row('opcache', 'PHP OPcache', $rate >= 99 || ! $production ? 'ok' : 'warn',
            sprintf('%.1f%% hits', $rate), self::bytes($free).' free; '.((int) ($stats['num_cached_scripts'] ?? 0)).' scripts cached');
    }

    /**
     * E-11 / E-13 (D-01, D-02): video storage driver and ffmpeg on the media worker's server.
     *
     * @return array{key: string, label: string, status: string, value: string, detail: string|null}
     */
    private function video(): array
    {
        $driver = (string) config('video.driver', 'local');
        $ffmpeg = (string) Cache::remember('health:ffmpeg', 600, function (): string {
            try {
                $result = Process::timeout(5)->run([(string) config('video.processing.ffmpeg', 'ffmpeg'), '-version']);

                return $result->successful() ? (string) strtok($result->output(), "\n") : '';
            } catch (\Throwable) {
                return '';
            }
        });
        $s3Ready = $driver !== 's3' || (config('video.s3.endpoint') && config('video.s3.bucket') && config('video.s3.key') && config('video.s3.secret'));
        $warn = $ffmpeg === '' || ! $s3Ready || ($driver === 'local' && app()->isProduction());
        $detail = match (true) {
            ! $s3Ready => 'VIDEO_DRIVER=s3 but VIDEO_S3_* settings are incomplete.',
            $ffmpeg === '' => 'ffmpeg not found: videos upload but get no poster or web copy. Install ffmpeg (see the Runbook: Video).',
            $driver === 'local' && app()->isProduction() => 'Videos are on the server disk. Set VIDEO_DRIVER=s3 (Cloudflare R2) for large files.',
            default => $ffmpeg,
        };

        return $this->row('video', 'Video storage + processing', $warn ? 'warn' : 'ok', $driver === 's3' ? 'Bucket (S3/R2)' : 'Server disk', $detail);
    }

    /**
     * @return array{key: string, label: string, status: string, value: string, detail: string|null}
     */
    private function version(): array
    {
        return $this->row('version', 'App version', 'ok', (string) config('app.version', 'dev'), 'PHP '.PHP_VERSION.' · Laravel '.app()->version());
    }

    /**
     * @return array{key: string, label: string, status: string, value: string, detail: string|null}
     */
    private function row(string $key, string $label, string $status, string $value, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'value' => $value, 'detail' => $detail];
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($n, 1)).' '.$units[$i];
    }
}
