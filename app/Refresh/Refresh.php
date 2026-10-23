<?php

namespace App\Refresh;

use App\MangaDex\RateLimit\Priority;
use InvalidArgumentException;

/**
 * One repository refresh call, small enough to cross into an async task or a queued job.
 * Arguments must be JSON values (strings, numbers, arrays); the priority is added when it runs.
 */
final readonly class Refresh
{
    /** @param  class-string  $repository */
    private function __construct(
        public string $repository,
        public string $method,
        public array $args,
    ) {}

    /** @param  array{0: class-string, 1: string}  $call */
    public static function of(array $call, mixed ...$args): self
    {
        [$repository, $method] = $call;

        if (! method_exists($repository, $method)) {
            throw new InvalidArgumentException("Unknown refresh [{$repository}::{$method}].");
        }

        if (json_encode($args) === false || json_decode(json_encode($args), true) !== $args) {
            throw new InvalidArgumentException("Refresh arguments for [{$method}] must be JSON values.");
        }

        return new self($repository, $method, array_values($args));
    }

    public function key(): string
    {
        return sha1(json_encode([$this->repository, $this->method, $this->args]));
    }

    public function run(Priority $priority): mixed
    {
        return app($this->repository)->{$this->method}(...$this->args, priority: $priority);
    }
}
