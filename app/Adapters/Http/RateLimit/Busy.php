<?php

namespace App\Adapters\Http\RateLimit;

final readonly class Busy
{
    /** @param  float  $retryAt  Unix time (with microseconds) when a slot may be free */
    public function __construct(
        public float $retryAt,
        public BusyReason $reason,
    ) {}

    public function secondsFrom(float $now): float
    {
        return max(0.0, $this->retryAt - $now);
    }
}
