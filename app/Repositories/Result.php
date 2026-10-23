<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;
use Countable;

final readonly class Result
{
    public function __construct(
        public mixed $data,
        public ?CarbonImmutable $fetchedAt,
        public bool $isStale,
    ) {}

    public static function empty(): self
    {
        return new self(null, null, true);
    }

    public function isEmpty(): bool
    {
        return match (true) {
            $this->data === null => true,
            is_array($this->data), $this->data instanceof Countable => count($this->data) === 0,
            default => false,
        };
    }
}
