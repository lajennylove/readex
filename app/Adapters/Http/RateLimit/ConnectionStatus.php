<?php

namespace App\Adapters\Http\RateLimit;

use Carbon\CarbonImmutable;

final readonly class ConnectionStatus
{
    public function __construct(
        public ConnectionState $state,
        public ?CarbonImmutable $pausedUntil,
        public bool $banned,
        public int $requestsLastMinute,
        public int $requestsLastHour,
    ) {}
}
