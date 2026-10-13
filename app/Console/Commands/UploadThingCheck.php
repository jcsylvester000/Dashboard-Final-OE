<?php

namespace App\Console\Commands;

use App\Domain\Files\DriveService;
use App\Domain\Files\UploadThing\UploadThingClient;
use App\Domain\Files\UploadThing\UploadThingException;
use Illuminate\Console\Command;

/**
 * Checks the UploadThing connection: token parses, API key works, and whether the
 * app allows private files (per-file ACL). Never prints the key.
 */
class UploadThingCheck extends Command
{
    /** @var string */
    protected $signature = 'uploadthing:check';

    /** @var string */
    protected $description = 'Check the UploadThing token, app settings and storage usage';

    public function handle(UploadThingClient $client): int
    {
        if (! $client->configured()) {
            $this->error('UPLOADTHING_TOKEN is missing or not a valid v7 token (base64 JSON with apiKey and appId).');

            return self::FAILURE;
        }

        $this->line('App id: '.$client->appId());

        try {
            $info = $client->appInfo();
            $usage = $client->usage();
        } catch (UploadThingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $acl = config('services.uploadthing.acl');
        $this->line('Default ACL: '.($info['defaultACL'] ?? '?').' · per-file ACL allowed: '.(($info['allowACLOverride'] ?? false) ? 'yes' : 'no'));
        $this->line('App storage used: '.DriveService::human((int) ($usage['appTotalBytes'] ?? 0)).' of '.DriveService::human((int) ($usage['limitBytes'] ?? 0)).' · files: '.($usage['filesUploaded'] ?? 0));

        if ($acl === 'private' && ! ($info['allowACLOverride'] ?? false) && ($info['defaultACL'] ?? '') !== 'private') {
            $this->warn('UPLOADTHING_ACL=private but the app does not allow per-file ACLs.');
            $this->warn('Either turn on "Allow overriding ACL" (or set the default ACL to private) in the UploadThing dashboard,');
            $this->warn('or set UPLOADTHING_ACL= (empty) in .env to use the app default.');

            return self::FAILURE;
        }

        $this->info('UploadThing is ready.');

        return self::SUCCESS;
    }
}
