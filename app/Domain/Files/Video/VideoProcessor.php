<?php

namespace App\Domain\Files\Video;

use App\Domain\Files\FileStore;
use App\Models\Attachment;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * E-13 (decision D-02): ffprobe reads duration, size, frame rate and codecs; ffmpeg
 * makes a poster frame and a web-playable proxy (H.264/AAC MP4, max 1080p, faststart).
 * Files that are already web-safe are only remuxed. The original is never changed.
 */
class VideoProcessor
{
    public function __construct(
        private readonly VideoStorage $storage,
        private readonly FileStore $thumbs,
    ) {}

    public function process(Attachment $attachment): void
    {
        $source = null;
        $poster = null;
        $proxy = null;
        try {
            $source = $this->storage->localCopy($attachment->key);
            $info = $this->probe($source);

            $poster = $this->tempPath('jpg');
            $this->ffmpeg([
                '-ss', sprintf('%.2f', min(1.0, $info['duration_ms'] / 10000)), '-i', $source,
                '-frames:v', '1', '-vf', "scale=-2:'min(720,ih)'", '-q:v', '3', '-y', $poster,
            ], 120);
            $posterKey = 'thumbnails/'.$attachment->workspace_id.'/'.Str::uuid()->toString().'.jpg';
            $this->thumbs->putContents($posterKey, $this->read($poster));

            $proxy = $this->tempPath('mp4');
            $this->ffmpeg($this->proxyArgs($source, $proxy, $info), (int) config('video.processing.timeout', 3600));
            $proxyKey = $attachment->key.'.proxy.mp4';
            $this->storage->putFile($proxyKey, $proxy, 'video/mp4');

            $old = $attachment->thumbnail_key;
            $attachment->forceFill([
                'media_status' => 'ready',
                'media' => array_diff_key($info, ['pix_fmt' => true, 'container' => true]),
                'media_error' => null,
                'thumbnail_key' => $posterKey,
                'proxy_key' => $proxyKey,
            ])->save();
            if ($old !== null && $old !== $posterKey) {
                $this->thumbs->delete($old);
            }
        } catch (Throwable $e) {
            report($e);
            $attachment->forceFill([
                'media_status' => 'failed',
                'media_error' => Str::limit($e instanceof RuntimeException ? $e->getMessage() : 'Processing failed.', 480),
            ])->save();
        } finally {
            foreach ([$poster, $proxy] as $tmp) {
                if ($tmp !== null && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
            if ($source !== null) {
                $this->storage->release($source);
            }
        }
    }

    /**
     * @return array{duration_ms: int, width: int, height: int, fps: float, video_codec: string, audio_codec: string|null, pix_fmt: string, container: string}
     */
    public function probe(string $path): array
    {
        $result = Process::timeout(120)->run([
            (string) config('video.processing.ffprobe', 'ffprobe'), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ]);
        if (! $result->successful()) {
            throw new RuntimeException('Not a readable video file.');
        }
        $data = json_decode($result->output(), true);
        $streams = is_array($data) && is_array($data['streams'] ?? null) ? $data['streams'] : [];
        $video = null;
        $audio = null;
        foreach ($streams as $s) {
            if (! is_array($s)) {
                continue;
            }
            $type = $s['codec_type'] ?? null;
            if ($type === 'video' && $video === null && ($s['disposition']['attached_pic'] ?? 0) !== 1) {
                $video = $s;
            } elseif ($type === 'audio' && $audio === null) {
                $audio = $s;
            }
        }
        if ($video === null) {
            throw new RuntimeException('No video track found. Upload an MP4, MOV, WebM or MKV video.');
        }

        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);
        $rotation = abs((int) ($video['tags']['rotate'] ?? 0));
        foreach ((array) ($video['side_data_list'] ?? []) as $side) {
            if (is_array($side) && isset($side['rotation'])) {
                $rotation = abs((int) $side['rotation']);
            }
        }
        if ($rotation === 90 || $rotation === 270) {
            [$width, $height] = [$height, $width]; // phone footage shot upright
        }
        $format = is_array($data['format'] ?? null) ? $data['format'] : [];
        $duration = (float) ($format['duration'] ?? $video['duration'] ?? 0);

        return [
            'duration_ms' => (int) round($duration * 1000),
            'width' => $width,
            'height' => $height,
            'fps' => self::rate((string) ($video['avg_frame_rate'] ?? '0/1')) ?: self::rate((string) ($video['r_frame_rate'] ?? '0/1')),
            'video_codec' => (string) ($video['codec_name'] ?? 'unknown'),
            'audio_codec' => $audio !== null ? (string) ($audio['codec_name'] ?? 'unknown') : null,
            'pix_fmt' => (string) ($video['pix_fmt'] ?? ''),
            'container' => (string) ($format['format_name'] ?? ''),
        ];
    }

    /**
     * Copy when already web-safe (H.264 + AAC/no audio in MP4, 8-bit 4:2:0, <= max height); else transcode.
     *
     * @param  array{duration_ms: int, width: int, height: int, fps: float, video_codec: string, audio_codec: string|null, pix_fmt: string, container: string}  $info
     * @return list<string>
     */
    public function proxyArgs(string $source, string $target, array $info): array
    {
        $max = (int) config('video.processing.max_height', 1080);
        $webSafe = $info['video_codec'] === 'h264'
            && in_array($info['audio_codec'], ['aac', null], true)
            && str_contains($info['container'], 'mp4')
            && $info['pix_fmt'] === 'yuv420p'
            && min($info['width'], $info['height']) <= $max;

        if ($webSafe) {
            return ['-i', $source, '-map', '0:v:0', '-map', '0:a:0?', '-c', 'copy', '-movflags', '+faststart', '-y', $target];
        }

        return [
            '-i', $source, '-map', '0:v:0', '-map', '0:a:0?',
            '-c:v', 'libx264', '-preset', (string) config('video.processing.preset', 'veryfast'), '-crf', (string) config('video.processing.crf', 23),
            '-pix_fmt', 'yuv420p', '-vf', "scale=-2:'min({$max},ih)'",
            '-c:a', 'aac', '-b:a', (string) config('video.processing.audio_bitrate', '128k'),
            '-movflags', '+faststart', '-y', $target,
        ];
    }

    /**
     * @param  list<string>  $args
     */
    private function ffmpeg(array $args, int $timeout): void
    {
        $result = Process::timeout($timeout)->run([(string) config('video.processing.ffmpeg', 'ffmpeg'), '-v', 'error', '-nostdin', ...$args]);
        if (! $result->successful()) {
            throw new RuntimeException('Could not convert the video: '.Str::limit(trim($result->errorOutput()) ?: 'ffmpeg failed', 300));
        }
    }

    private function read(string $path): string
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Could not make a poster frame.');
        }

        return $bytes;
    }

    private function tempPath(string $ext): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'oe-'.Str::uuid()->toString().'.'.$ext;
    }

    private static function rate(string $fraction): float
    {
        [$num, $den] = array_pad(explode('/', $fraction), 2, '1');

        return (float) $den > 0 ? round((float) $num / (float) $den, 3) : 0.0;
    }
}
