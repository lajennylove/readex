<?php

declare(strict_types=1);

namespace App\Sources;

use RuntimeException;

/**
 * Thrown by ActiveSource when the user hasn't configured a source URL yet.
 * Setup is the gatekeeper: it writes the URL and Home opens; without a URL
 * the app returns to Setup on next launch.
 */
final class NoSourceConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No source URL configured. First-run Setup must save one.');
    }
}
