<?php

namespace App\Jobs;

use App\Domain\Files\Video\VideoProcessor;
use App\Models\Attachment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * E-13 (D-02): poster, details and web proxy for an uploaded video, on its own
 * "media" queue (one worker, low priority) so long encodes never delay alerts or PDFs.
 */
class ProcessVideo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout;

    public function __construct(public readonly int $attachmentId)
    {
        $this->timeout = (int) config('video.processing.timeout', 3600);
        $this->onQueue((string) config('video.processing.queue', 'media'));
    }

    public function uniqueId(): string
    {
        return (string) $this->attachmentId;
    }

    public function handle(VideoProcessor $processor): void
    {
        $attachment = Attachment::query()->find($this->attachmentId);
        if ($attachment === null || ! $attachment->isVideoAsset() || $attachment->upload_state !== null) {
            return;
        }
        $processor->process($attachment);
    }

    public function failed(?Throwable $e): void
    {
        // Timeout or worker crash: say so on the file; the original stays downloadable.
        Attachment::query()->whereKey($this->attachmentId)->where('media_status', 'processing')
            ->update(['media_status' => 'failed', 'media_error' => 'Processing stopped (timeout or worker restart). Upload again or ask an admin to retry.']);
    }
}
