<?php

namespace App\Repositories;

use App\Models\SourceConfig;
use App\Sources\MangaDexSource;
use App\Sources\SourceHealthCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Phase 8 Task 2/3 (Plan-08 §2, D45 §2/§6 item 3, Jenny 2026-10-24 17:54 EDT): the `sources`
 * table — which sources are configured, enabled and blocked. The built-in MangaDex row is
 * seeded once and can never be removed (only blocked/unblocked, like any other source);
 * user-added sources hold a generic `config` blob (base URL / API endpoint, optional
 * credentials) this repository never inspects or validates against any specific site's shape,
 * per D45 §2 (no app-curated source list).
 *
 * Every URL a user types — at first-run Setup or later from Settings → Sources — is health
 * checked (one GET, expect 2xx) before it's saved (Jenny: "validate if we get any valid
 * response from the api (status) before saving it. Otherwise ask the user to check that
 * source."). A URL that matches the built-in MangaDex source's own real API URL is recognized
 * as "the built-in source", not duplicated as a second row — see `matchesBuiltIn()`.
 */
final class SourceRepository
{
    public function __construct(private readonly SourceHealthCheck $health) {}

    /** Ensures the built-in row exists, called once at boot (idempotent). Keeps the label in sync with the adapter's current label on existing installs. */
    public function seedBuiltIn(): void
    {
        $label = app(MangaDexSource::class)->label();

        $row = SourceConfig::query()->firstOrCreate(
            ['key' => MangaDexSource::KEY],
            ['label' => $label, 'kind' => 'builtin', 'enabled' => true, 'blocked' => false, 'created_at' => Carbon::now()],
        );

        if ($row->label !== $label) {
            $row->update(['label' => $label]);
        }
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

    /** True while the built-in MangaDex source is enabled and not blocked — the only source with a real content adapter today (Task 1). */
    public function mangaDexEnabled(): bool
    {
        return $this->enabled()->contains(fn (SourceConfig $s) => $s->key === MangaDexSource::KEY);
    }

    public function find(string $key): ?SourceConfig
    {
        return SourceConfig::query()->find($key);
    }

    /** The real, fixed base URL of the built-in MangaDex adapter — never the sources table's own `config`. */
    public function builtInMangaDexUrl(): string
    {
        return rtrim(config('source.api_url'), '/');
    }

    /** A typed URL that is really just MangaDex's own documented API, loosely (trailing slash, case). */
    public function matchesBuiltIn(string $url): bool
    {
        return rtrim(mb_strtolower(trim($url)), '/') === mb_strtolower($this->builtInMangaDexUrl());
    }

    /**
     * Adds a user source from a generic URL/API endpoint, health-checked first. Never validated
     * against, or named after, any specific third-party site (D45 §2) — just a non-empty label
     * and a URL that actually answers.
     *
     * @throws InvalidArgumentException bad shape, or the health check failed
     */
    public function add(string $label, string $baseUrl): SourceConfig
    {
        $label = trim($label);

        if ($label === '') {
            throw new InvalidArgumentException('A source needs a name.');
        }

        $baseUrl = $this->validated($baseUrl);

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

    /**
     * Setup's one-field version (Jenny 2026-10-24 17:54 EDT): the URL is the only thing asked
     * for, health-checked the same way. A URL that matches MangaDex's own API just enables the
     * already-seeded built-in row (never a second row for the same real endpoint); anything else
     * becomes a new user source, labeled after its host (generic, never a suggested name).
     *
     * @throws InvalidArgumentException bad shape, or the health check failed
     */
    public function addFromSetup(string $baseUrl): SourceConfig
    {
        $baseUrl = $this->validated($baseUrl);

        if ($this->matchesBuiltIn($baseUrl)) {
            $this->seedBuiltIn();
            $this->setEnabled(MangaDexSource::KEY, true);
            $this->setBlocked(MangaDexSource::KEY, false);

            return $this->find(MangaDexSource::KEY);
        }

        $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;

        return $this->add($host, $baseUrl);
    }

    /**
     * Changes a user source's URL, health-checked the same way as `add()`. The built-in
     * MangaDex source's URL is the app's real, fixed API config — not user-editable through
     * this generic mechanism (D45 §2: the built-in adapter is not a "pointed at" source).
     *
     * @throws InvalidArgumentException bad shape, the health check failed, or it's the built-in row
     */
    public function updateUrl(string $key, string $baseUrl): SourceConfig
    {
        $source = $this->find($key);

        if ($source === null || $source->kind === 'builtin') {
            throw new InvalidArgumentException("Source [{$key}] can't have its URL changed here.");
        }

        $baseUrl = $this->validated($baseUrl);
        $source->update(['config' => ['base_url' => $baseUrl]]);

        return $source->fresh();
    }

    /** @throws InvalidArgumentException */
    private function validated(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Not a valid URL.');
        }

        if (! $this->health->check($baseUrl)) {
            throw new InvalidArgumentException("Couldn't connect to that source. Check the URL and try again.");
        }

        return $baseUrl;
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
