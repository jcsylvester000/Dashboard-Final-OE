<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * E-91, nightly: move audit rows older than the retention period into monthly CSV files
 * on the private disk (storage/app/private/archives/activity/YYYY-MM.csv), then remove
 * them from the table. Money rows (billing.*) are kept online longer. Rerunning moves
 * nothing new; each row is written once and deleted in the same step.
 */
class ArchiveActivityLog extends Command
{
    /** @var string */
    protected $signature = 'activity:archive';

    /** @var string */
    protected $description = 'Archive audit log rows older than the retention period';

    public const DIR = 'archives/activity';

    public function handle(): int
    {
        $cutoff = now()->subMonthsNoOverflow(max(1, (int) config('security.activity_retention_months', 24)))->startOfDay();
        $billingCutoff = now()->subMonthsNoOverflow(max(1, (int) config('security.billing_activity_retention_months', 120)))->startOfDay();
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);
        $moved = 0;

        $query = DB::table('activity_log')
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->where('action', 'not like', 'billing.%')->orWhere('created_at', '<', $billingCutoff))
            ->orderBy('id');

        $query->chunkById(1000, function ($rows) use ($disk, &$moved) {
            $byMonth = [];
            foreach ($rows as $row) {
                $byMonth[substr((string) $row->created_at, 0, 7)][] = $row;
            }
            foreach ($byMonth as $month => $list) {
                $path = $disk->path(self::DIR.'/'.$month.'.csv');
                $new = ! is_file($path);
                $out = fopen($path, 'a');
                if ($out === false) {
                    throw new \RuntimeException("Cannot write {$path}");
                }
                if ($new) {
                    fputcsv($out, ['id', 'created_at', 'actor_id', 'action', 'subject_type', 'subject_id', 'properties', 'ip_address', 'user_agent']);
                }
                foreach ($list as $r) {
                    fputcsv($out, [$r->id, $r->created_at, $r->actor_id, $r->action, $r->subject_type, $r->subject_id, $r->properties, $r->ip_address, $r->user_agent]);
                }
                fclose($out);
            }
            // The model refuses deletes (append-only); archiving is the one sanctioned removal.
            DB::table('activity_log')->whereIn('id', $rows->pluck('id'))->delete();
            $moved += $rows->count();
        });

        $this->info("Archived {$moved} activity rows.");

        return self::SUCCESS;
    }
}
