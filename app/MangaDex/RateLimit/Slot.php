<?php

namespace App\MangaDex\RateLimit;

final readonly class Slot
{
    /** @param  list<string>  $buckets */
    public function __construct(
        public Call $call,
        public Priority $priority,
        public array $buckets,
        public ?string $endpoint,
        public ?string $family,
        public bool $isProbe = false,
    ) {}

    public function usageKey(): string
    {
        return $this->endpoint ?? $this->buckets[0];
    }
}
