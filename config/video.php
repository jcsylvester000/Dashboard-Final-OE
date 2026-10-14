<?php

/*
| Video deliverables (E-11..E-13, decisions D-01 and D-02).
| Large videos never pass through PHP: the browser uploads parts straight to
| S3-compatible storage (Cloudflare R2 by default) and plays them back with
| short-lived presigned links (HTTP Range, 206). The "local" driver keeps the
| same flow on the private local disk for development and tests.
*/
return [
    'driver' => env('VIDEO_DRIVER', 'local'), // local | s3

    's3' => [
        // R2: https://<account-id>.r2.cloudflarestorage.com, region "auto".
        'endpoint' => env('VIDEO_S3_ENDPOINT'),
        'region' => env('VIDEO_S3_REGION', 'auto'),
        'bucket' => env('VIDEO_S3_BUCKET'),
        'key' => env('VIDEO_S3_KEY'),
        'secret' => env('VIDEO_S3_SECRET'),
        'path_style' => (bool) env('VIDEO_S3_PATH_STYLE', true),
    ],

    'extensions' => ['mp4', 'mov', 'm4v', 'webm', 'mkv'],
    'max_gb' => (float) env('VIDEO_MAX_GB', 5),
    // Each part is uploaded (and retried) separately. S3 needs >= 5 MB parts and at most 10,000 parts.
    'part_mb' => (int) env('VIDEO_PART_MB', 16),
    // Per client (workspace), counting every version that is not deleted.
    'quota_gb' => (float) env('VIDEO_QUOTA_GB', 50),
    // Presigned upload and playback links live this long.
    'link_minutes' => (int) env('VIDEO_LINK_MINUTES', 15),
    // Unfinished uploads are aborted after this many hours (video:prune-uploads).
    'abandon_hours' => 24,

    'processing' => [
        'queue' => env('VIDEO_QUEUE', 'media'),
        'timeout' => (int) env('VIDEO_JOB_TIMEOUT', 3600),
        'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),
        'max_height' => 1080,
        'crf' => 23,
        'preset' => 'veryfast',
        'audio_bitrate' => '128k',
    ],
];
