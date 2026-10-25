<?php

namespace App\Adapters\Http\RateLimit;

enum Priority: string
{
    case Interactive = 'interactive';
    case Prefetch = 'prefetch';
    case Bulk = 'bulk';

    public function isBackground(): bool
    {
        return $this !== self::Interactive;
    }
}
