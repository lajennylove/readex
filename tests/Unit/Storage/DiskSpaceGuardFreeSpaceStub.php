<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Test-only override: PHP resolves an unqualified function call against the *calling*
 * namespace before falling back to the global function, so defining `disk_free_space()` here
 * lets DiskSpaceGuardTest fake the free-space value without touching the real filesystem.
 * Falls through to the real function whenever the test hasn't set a fake value.
 */
function disk_free_space(string $path): float|false
{
    return $GLOBALS['__readex_fake_disk_free_space'] ?? \disk_free_space($path);
}
