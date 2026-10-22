<?php

namespace App\MangaDex\RateLimit;

final readonly class BucketDefinition
{
    public function __construct(
        public string $key,
        public float $capacity,
        public float $perSeconds,
        public ?int $limit = null,
        public bool $adaptive = false,
    ) {}

    public function refillPerSec(): float
    {
        return $this->perSeconds > 0 ? $this->capacity / $this->perSeconds : 0.0;
    }

    /** Our budget when MangaDex reports $limit instead of the documented one. */
    public function capacityFor(int $limit): float
    {
        if ($this->limit === null || $this->limit === 0) {
            return (float) $limit;
        }

        return max(1.0, floor($limit * $this->capacity / $this->limit));
    }
}
