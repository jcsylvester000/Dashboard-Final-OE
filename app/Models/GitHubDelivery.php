<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * E-24: one webhook delivery. The unique delivery id makes processing idempotent
 * (GitHub redeliveries and replays are acknowledged and ignored).
 *
 * @property int $id
 * @property string $delivery_id
 * @property string $event
 * @property string|null $repository
 * @property string $status queued | done | ignored | failed
 * @property string|null $result
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['delivery_id', 'event', 'repository', 'status', 'result', 'processed_at'])]
class GitHubDelivery extends Model
{
    protected $table = 'github_deliveries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
