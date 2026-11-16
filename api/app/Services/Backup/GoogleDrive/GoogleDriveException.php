<?php

declare(strict_types=1);

namespace App\Services\Backup\GoogleDrive;

use RuntimeException;

/** A Google call that failed, with a message that is safe to show: never a token. */
class GoogleDriveException extends RuntimeException {}
