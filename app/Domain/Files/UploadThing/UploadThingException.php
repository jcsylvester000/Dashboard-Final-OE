<?php

namespace App\Domain\Files\UploadThing;

use RuntimeException;

/**
 * Any failure talking to UploadThing. The message is safe to show to staff
 * (it never contains the API key).
 */
class UploadThingException extends RuntimeException {}
