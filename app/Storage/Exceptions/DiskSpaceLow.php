<?php

declare(strict_types=1);

namespace App\Storage\Exceptions;

use RuntimeException;

/**
 * The app's storage volume has less than `readex.storage.min_free_bytes` free
 * (`disk_free_space()` against the caches path). Thrown instead of writing, so a caller can
 * skip or release the job rather than making a nearly-full device worse.
 */
final class DiskSpaceLow extends RuntimeException
{
    public function __construct(public readonly int $freeBytes, public readonly int $minFreeBytes)
    {
        parent::__construct("Only {$freeBytes} bytes free, below the {$minFreeBytes} byte minimum.");
    }
}
