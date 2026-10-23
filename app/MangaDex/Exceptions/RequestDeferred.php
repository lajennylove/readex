<?php

namespace App\MangaDex\Exceptions;

use App\MangaDex\RateLimit\Busy;
use RuntimeException;

/** No request slot within maxWait. Screens keep saved data; jobs release() until $busy->retryAt. */
final class RequestDeferred extends RuntimeException
{
    public function __construct(public readonly Busy $busy)
    {
        parent::__construct("MangaDex request deferred ({$busy->reason->value}) until {$busy->retryAt}");
    }
}
