<?php

namespace App\Repositories;

use App\Models\SourceConfig;
use App\Sources\MangaDexSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Phase 8 Task 2 (Plan-08 §2, D45 §2/§6 item 3): the `sources` table — which sources are
 * configured, enabled and blocked. The built-in MangaDex row is seeded once and can never be
 * removed (only blocked/unblocked, like any other source); user-added sources hold a generic
 * `config` blob (base URL / API endpoint, optional credentials) this repository never inspects
 * or validates against any specific site's shape, per D45 §2 (no app-curated source list).
 */
final class SourceRepository
{
    /** Ensures the built-in MangaDex row exists, called once at boot (idempotent). */
    public function seedBuiltIn(): void
    {
        SourceConfig::query()->firstOrCreate(
            ['key' => MangaDexSource::KEY],
            ['label' => 'MangaDex', 'kind' => 'builtin', 'enabled' => true, 'blocked' => false, 'created_at' => Carbon::now()],
        );
    }

    /** @return Collection<int, SourceConfig> every configured source, built-in first. */
    public function all(): Collection
    {
        $this->seedBuiltIn();

        return SourceConfig::query()->orderByRaw("kind = 'builtin' desc")->orderBy('created_at')->get();
    }

    /** @return Collection<int, SourceConfig> sources usable right now: enabled and not blocked. */
    public function enabled(): Collection
    {
        return $this->all()->filter(fn (SourceConfig $s) => $s->enabled && ! $s->blocked)->values();
    }

    public function find(string $key): ?SourceConfig
    {
        return SourceConfig::query()->find($key);
    }

    /**
     * Adds a user source from a generic URL/API endpoint. Never validated against, or named
     * after, any specific third-party site (D45 §2) — just a non-empty label and a URL shape.
     */
    public function add(string $label, string $baseUrl): SourceConfig
    {
        $label = trim($label);
        $baseUrl = trim($baseUrl);

        if ($label === '') {
            throw new InvalidArgumentException('A source needs a name.');
        }

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Not a valid URL.');
        }

        $key = 'user:'.(string) Str::uuid();

        return SourceConfig::query()->create([
            'key' => $key,
            'label' => $label,
            'kind' => 'user',
            'enabled' => true,
            'blocked' => false,
            'config' => ['base_url' => $baseUrl],
            'created_at' => Carbon::now(),
        ]);
    }

    /** The built-in source is never deleted (D45 §2: it ships with the app); a user source is removed outright. */
    public function remove(string $key): void
    {
        $source = $this->find($key);

        if ($source !== null && $source->kind !== 'builtin') {
            $source->delete();
        }
    }

    /** D45 §6 item 3: simple local block/hide, no reporting backend, no confirmation flow beyond the Settings UI's own. */
    public function setBlocked(string $key, bool $blocked): void
    {
        $this->find($key)?->update(['blocked' => $blocked]);
    }

    public function setEnabled(string $key, bool $enabled): void
    {
        $this->find($key)?->update(['enabled' => $enabled]);
    }
}
