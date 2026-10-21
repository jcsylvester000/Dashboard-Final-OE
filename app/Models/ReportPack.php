<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * E-51: a scheduled PDF report for one client, saved to the client's files and
 * announced by link (no email).
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property list<string> $sections
 * @property string $frequency weekly | monthly
 * @property int $day weekday 1-7 (Mon-Sun) or day of month 1-28
 * @property list<int> $recipient_ids
 * @property bool $enabled
 * @property Carbon $next_run_on
 * @property Carbon|null $last_run_at
 * @property int $runs
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'sections', 'frequency', 'day', 'recipient_ids', 'enabled', 'next_run_on', 'created_by'])]
class ReportPack extends Model
{
    public const SECTIONS = [
        'hours' => 'Hours by department and person',
        'completed' => 'Tasks completed',
        'open' => 'Open work by status',
        'overdue' => 'Overdue tasks',
        'estimates' => 'Estimate vs actual',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sections' => 'array', 'recipient_ids' => 'array', 'enabled' => 'boolean', 'day' => 'integer', 'next_run_on' => 'date', 'last_run_at' => 'datetime', 'runs' => 'integer'];
    }
}
