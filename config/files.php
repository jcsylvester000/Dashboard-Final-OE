<?php

/*
| Attachments (P8). The size limit and allowed types can be changed by an admin
| (Admin > File settings); these are the defaults.
*/
return [
    /*
    | Where task/project attachments go (R-03, D-06):
    |  auto        = UploadThing when it is configured AND files are private (UPLOADTHING_ACL=private,
    |                paid plan); otherwise the private local disk. Default and recommended.
    |  uploadthing = always UploadThing (on the free plan that means public-by-URL files).
    |  local       = always the private local disk.
    | Thumbnails always stay on the local disk (small, regenerable).
    */
    'driver' => env('FILES_DRIVER', 'auto'),
    'local_disk' => env('FILES_LOCAL_DISK', 'local'),

    'max_mb' => (int) env('FILES_MAX_MB', 25),

    // Allowed groups: extension => mime types checked against the file contents.
    'groups' => [
        'images' => ['label' => 'Images (JPG, PNG, WebP, GIF, AVIF)', 'ext' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif']],
        'pdf' => ['label' => 'PDF', 'ext' => ['pdf']],
        'office' => ['label' => 'Office (Word, Excel, PowerPoint)', 'ext' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']],
        'text' => ['label' => 'Text, logs, JSON and CSV', 'ext' => ['txt', 'csv', 'md', 'log', 'json']], // E-23: log and JSON viewer
        'archives' => ['label' => 'ZIP archives', 'ext' => ['zip']],
        'video' => ['label' => 'Video (MP4, WebM)', 'ext' => ['mp4', 'webm']],
    ],
    'default_groups' => ['images', 'pdf', 'office', 'text'],

    // Signed download links expire after this many minutes.
    'link_minutes' => (int) env('FILES_LINK_MINUTES', 10),

    /*
    | Team file drive (My files), stored on UploadThing. Every member gets a quota;
    | admins get more. Uploads go straight from the browser to UploadThing.
    */
    'drive' => [
        'quota_mb' => [
            'member' => (int) env('DRIVE_QUOTA_MB', 100),
            'admin' => (int) env('DRIVE_ADMIN_QUOTA_MB', 300),
        ],
        'admin_roles' => ['super-admin', 'admin'],
        'groups' => ['images', 'pdf', 'office', 'text', 'archives', 'video'],
        // Unfinished uploads hold quota for this long, then get cleaned up.
        'pending_minutes' => 60,
        // Signed UploadThing links handed to the browser live this long (seconds).
        'link_seconds' => 300,
    ],
];
