<?php

namespace App\Sources;

/**
 * Where the app looks a source up by key (Plan-08 §2). Task 1 only registers the built-in
 * HTTP source adapter; Task 2 adds the `sources` table and user-added sources to this registry.
 */
final class SourceRegistry
{
    public function __construct(private readonly HttpSource $mangaDex) {}

    /** @return list<Source> every source the app currently knows, built-in first. */
    public function all(): array
    {
        return [$this->mangaDex];
    }

    public function find(string $key): ?Source
    {
        foreach ($this->all() as $source) {
            if ($source->key() === $key) {
                return $source;
            }
        }

        return null;
    }

    /** The built-in HTTP source adapter directly, for the call sites that are still single-source only this task (D45 §2). */
    public function mangaDex(): HttpSource
    {
        return $this->mangaDex;
    }
}
