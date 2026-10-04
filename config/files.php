<?php

/*
| Attachments (P8). The size limit and allowed types can be changed by an admin
| (Admin > File settings); these are the defaults.
*/
return [
    // local today; 'uploadthing' when that driver is added (integration later).
    'driver' => env('FILES_DRIVER', 'local'),
    'local_disk' => env('FILES_LOCAL_DISK', 'local'),

    'max_mb' => (int) env('FILES_MAX_MB', 25),

    // Allowed groups: extension => mime types checked against the file contents.
    'groups' => [
        'images' => ['label' => 'Images (JPG, PNG, WebP, GIF, AVIF)', 'ext' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif']],
        'pdf' => ['label' => 'PDF', 'ext' => ['pdf']],
        'office' => ['label' => 'Office (Word, Excel, PowerPoint)', 'ext' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']],
        'text' => ['label' => 'Text and CSV', 'ext' => ['txt', 'csv', 'md']],
        'archives' => ['label' => 'ZIP archives', 'ext' => ['zip']],
        'video' => ['label' => 'Video (MP4, WebM)', 'ext' => ['mp4', 'webm']],
    ],
    'default_groups' => ['images', 'pdf', 'office', 'text'],

    // Signed download links expire after this many minutes.
    'link_minutes' => (int) env('FILES_LINK_MINUTES', 10),
];
