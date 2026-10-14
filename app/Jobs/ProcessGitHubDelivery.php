<?php

namespace App\Jobs;

use App\Domain\Integrations\GitHubEvents;
use App\Models\GitHubDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * E-24: process one verified GitHub delivery off the request (GitHub expects a fast 2xx).
 */
class ProcessGitHubDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly int $deliveryId, public readonly string $event, public readonly array $payload) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(GitHubEvents $events): void
    {
        $delivery = GitHubDelivery::query()->find($this->deliveryId);
        if ($delivery === null || $delivery->processed_at !== null) {
            return; // already handled
        }
        $outcome = $events->handle($this->event, $this->payload);
        $delivery->forceFill([...$outcome, 'processed_at' => now()])->save();
    }

    public function failed(?Throwable $e): void
    {
        GitHubDelivery::query()->whereKey($this->deliveryId)->update(['status' => 'failed', 'result' => mb_substr($e?->getMessage() ?? 'failed', 0, 480)]);
    }
}
