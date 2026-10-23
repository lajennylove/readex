<?php

namespace App\MangaDex\RateLimit;

final class BucketRow
{
    public function __construct(
        public string $key,
        public float $capacity,
        public float $refillPerSec,
        public float $tokens,
        public float $updatedAt,
        public ?float $blockedUntil = null,
        public ?int $learnedLimit = null,
        public int $strikes = 0,
        public bool $probing = false,
        public float $adjustedAt = 0.0,
    ) {}

    public static function fresh(BucketDefinition $def, float $now): self
    {
        return new self($def->key, $def->capacity, $def->refillPerSec(), $def->capacity, $now, adjustedAt: $now);
    }

    public static function fromDb(object $row): self
    {
        return new self(
            key: $row->key,
            capacity: (float) $row->capacity,
            refillPerSec: (float) $row->refill_per_sec,
            tokens: (float) $row->tokens,
            updatedAt: (float) $row->updated_at,
            blockedUntil: $row->blocked_until === null ? null : (float) $row->blocked_until,
            learnedLimit: $row->learned_limit === null ? null : (int) $row->learned_limit,
            strikes: (int) $row->strikes,
            probing: (bool) $row->probing,
            adjustedAt: (float) $row->adjusted_at,
        );
    }

    /** @return array<string, mixed> */
    public function toDb(): array
    {
        return [
            'key' => $this->key,
            'capacity' => $this->capacity,
            'refill_per_sec' => $this->refillPerSec,
            'tokens' => $this->tokens,
            'updated_at' => $this->updatedAt,
            'blocked_until' => $this->blockedUntil,
            'learned_limit' => $this->learnedLimit,
            'strikes' => $this->strikes,
            'probing' => $this->probing,
            'adjusted_at' => $this->adjustedAt,
        ];
    }

    public function refill(float $now): void
    {
        $elapsed = max(0.0, $now - $this->updatedAt);
        $this->tokens = min($this->capacity, $this->tokens + $elapsed * $this->refillPerSec);
        $this->updatedAt = max($this->updatedAt, $now);
    }

    public function isBlocked(float $now): bool
    {
        return $this->blockedUntil !== null && $this->blockedUntil > $now;
    }

    /** Seconds until this bucket can hand out one token (0 = now). */
    public function waitFor(float $now): float
    {
        if ($this->isBlocked($now)) {
            return $this->blockedUntil - $now;
        }

        $notBefore = max(0.0, $this->updatedAt - $now);

        if ($this->tokens >= 1.0) {
            return $notBefore;
        }

        return $this->refillPerSec > 0 ? $notBefore + (1.0 - $this->tokens) / $this->refillPerSec : INF;
    }
}
