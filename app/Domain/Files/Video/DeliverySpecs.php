<?php

namespace App\Domain\Files\Video;

use App\Models\Attachment;
use App\Models\DeliverySpec;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * E-18: check an uploaded video against the delivery spec(s) for its task before a
 * reviewer spends time on it. A task uses the spec chosen on it, otherwise every spec
 * of the client whose channel is one of the task's Channels (and whose deliverable,
 * when set, matches the task's Deliverable).
 */
class DeliverySpecs
{
    /** Common ratios, so 1080x1920 reads "9:16" rather than "9:16" by luck. */
    private const NAMED = ['16:9' => 16 / 9, '9:16' => 9 / 16, '1:1' => 1.0, '4:5' => 4 / 5, '5:4' => 5 / 4, '4:3' => 4 / 3, '3:4' => 3 / 4, '21:9' => 21 / 9, '2:3' => 2 / 3, '3:2' => 3 / 2];

    /**
     * @return Collection<int, DeliverySpec>
     */
    public function forTask(Task $task): Collection
    {
        if ($task->delivery_spec_id !== null) {
            return DeliverySpec::query()->whereKey($task->delivery_spec_id)->where('workspace_id', $task->workspace_id)->get();
        }
        $channels = array_values(array_filter(array_map(fn ($c) => mb_strtolower(trim($c)), explode(',', (string) ($task->work_details['channel'] ?? '')))));
        if ($channels === []) {
            return new Collection;
        }
        $deliverable = mb_strtolower(trim((string) ($task->work_details['deliverable_type'] ?? '')));

        return DeliverySpec::query()->where('workspace_id', $task->workspace_id)->whereNotNull('channel')->orderBy('id')->get()
            ->filter(fn (DeliverySpec $s) => in_array(mb_strtolower((string) $s->channel), $channels, true)
                && ($s->deliverable === null || mb_strtolower($s->deliverable) === $deliverable))
            ->values();
    }

    /**
     * @param  Collection<int, DeliverySpec>  $specs
     * @return list<array{spec_id: int, spec: string, pass: bool|null, issues: list<string>}>
     */
    public function check(Attachment $video, Collection $specs, int $captions): array
    {
        $out = [];
        foreach ($specs as $spec) {
            if ($video->media_status !== 'ready' || $video->media === null) {
                $out[] = ['spec_id' => $spec->id, 'spec' => $spec->name, 'pass' => null, 'issues' => []]; // checked once processed

                continue;
            }
            $issues = $this->issues($spec, $video->media, $video->size, $captions);
            $out[] = ['spec_id' => $spec->id, 'spec' => $spec->name, 'pass' => $issues === [], 'issues' => $issues];
        }

        return $out;
    }

    /**
     * @param  array{duration_ms?: int, width?: int, height?: int, fps?: float, video_codec?: string, audio_codec?: string|null}  $media
     * @return list<string>
     */
    public function issues(DeliverySpec $spec, array $media, int $bytes, int $captions): array
    {
        $w = (int) ($media['width'] ?? 0);
        $h = (int) ($media['height'] ?? 0);
        $issues = [];

        if ($spec->aspect !== null && $w > 0 && $h > 0) {
            $expected = self::ratio($spec->aspect);
            if ($expected !== null && abs(($w / $h) / $expected - 1) > 0.01) {
                $issues[] = 'Aspect '.self::label($w, $h).', expected '.$spec->aspect;
            }
        }
        if (($spec->min_width !== null && $w < $spec->min_width) || ($spec->min_height !== null && $h < $spec->min_height)) {
            $issues[] = "Size {$w}×{$h}, expected at least ".($spec->min_width ?? '?').'×'.($spec->min_height ?? '?');
        }
        $seconds = (int) round(($media['duration_ms'] ?? 0) / 1000);
        if ($spec->max_duration_s !== null && $seconds > $spec->max_duration_s) {
            $issues[] = 'Length '.self::clock($seconds).', max '.self::clock($spec->max_duration_s);
        }
        $mb = (int) ceil($bytes / 1024 / 1024);
        if ($spec->max_size_mb !== null && $mb > $spec->max_size_mb) {
            $issues[] = "File {$mb} MB, max {$spec->max_size_mb} MB";
        }
        if ($spec->captions_required && $captions === 0) {
            $issues[] = 'Captions missing';
        }

        return $issues;
    }

    /** "9:16" -> 0.5625; null when not a ratio. */
    public static function ratio(string $aspect): ?float
    {
        if (preg_match('/^(\d{1,3}):(\d{1,3})$/', trim($aspect), $m) !== 1 || (int) $m[1] === 0 || (int) $m[2] === 0) {
            return null;
        }

        return (int) $m[1] / (int) $m[2];
    }

    /** 1920x1080 -> "16:9"; odd sizes -> nearest named ratio within 1 %, else reduced "w:h". */
    public static function label(int $w, int $h): string
    {
        foreach (self::NAMED as $name => $r) {
            if (abs(($w / $h) / $r - 1) <= 0.01) {
                return $name;
            }
        }
        $g = self::gcd($w, $h);

        return intdiv($w, $g).':'.intdiv($h, $g);
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? max(1, $a) : self::gcd($b, $a % $b);
    }

    private static function clock(int $seconds): string
    {
        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}
