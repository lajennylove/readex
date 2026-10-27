<?php

namespace App\Repositories;

use App\Models\SourceConfig;
use App\Providers\AppServiceProvider;
use App\Sources\HttpSource;
use App\Sources\SourceHealthCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Phase 8 Task 2/3 (Plan-08 §2, D45 §2/§6 item 3): the `sources`
 * table — which sources are configured, enabled and blocked. The built-in row is
 * seeded once and can never be removed (only blocked/unblocked, like any other source);
 * user-added sources hold a generic `config` blob (base URL / API endpoint, optional
 * credentials) this repository never inspects or validates against any specific site's shape,
 * per D45 §2 (no app-curated source list).
 *
 * Every URL a user types — at first-run Setup or later from Settings → Sources — is health
 * checked (one GET, expect 2xx) before it's saved. A URL that matches the built-in source's own real API URL is recognized
 * as "the built-in source", not duplicated as a second row — see `matchesBuiltIn()`.
 */
final class SourceRepository
{
    public function __construct(private readonly SourceHealthCheck $health) {}

    /** Ensures the built-in row exists, called once at boot (idempotent). Keeps the label in sync with the adapter's current label on existing installs. */
    public function seedBuiltIn(): void
    {
        self::ensureSourcesTable();

        $label = app(HttpSource::class)->label();

        $row = SourceConfig::query()->firstOrCreate(
            ['key' => HttpSource::KEY],
            ['label' => $label, 'kind' => 'builtin', 'enabled' => true, 'blocked' => false, 'created_at' => Carbon::now()],
        );

        if ($row->label !== $label) {
            $row->update(['label' => $label]);
        }
    }

    /**
     * Point-of-use guard: if the `sources` table is missing on a device where the shipped
     * migration never ran, create it in place before the first query hits it. Idempotent.
     */
    public static function ensureSourcesTable(): void
    {
        if (Schema::hasTable('sources')) {
            return;
        }

        Schema::create('sources', function ($table) {
            $table->string('key')->primary();
            $table->string('label');
            $table->string('kind');
            $table->boolean('enabled')->default(true);
            $table->boolean('blocked')->default(false);
            $table->json('config')->nullable();
            $table->timestamp('created_at');
        });
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

    /** True while the built-in source is enabled and not blocked — the only source with a real content adapter today (Task 1). */
    public function builtInEnabled(): bool
    {
        return $this->enabled()->contains(fn (SourceConfig $s) => $s->key === HttpSource::KEY);
    }

    public function find(string $key): ?SourceConfig
    {
        return SourceConfig::query()->find($key);
    }

    /** The real, fixed base URL of the built-in adapter — never the sources table's own `config`. */
    public function builtInUrl(): string
    {
        return rtrim(config('source.api_url'), '/');
    }

    /** A typed URL that is really just the built-in source's own documented API, loosely (trailing slash, case). */
    public function matchesBuiltIn(string $url): bool
    {
        return rtrim(mb_strtolower(trim($url)), '/') === mb_strtolower($this->builtInUrl());
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

        $row = SourceConfig::query()->create([
            'key' => $key,
            'label' => $label,
            'kind' => 'user',
            'enabled' => true,
            'blocked' => false,
            'config' => ['base_url' => $baseUrl, 'meta' => $this->fetchMeta($baseUrl)],
            'created_at' => Carbon::now(),
        ]);

        AppServiceProvider::activateUserSource();

        return $row;
    }

    /**
     * Optional source-published metadata (covers_url, report_url, status_family, static_json).
     * Any failure or malformed response returns an empty array — the caller derives sensible
     * defaults instead.
     *
     * @return array<string, mixed>
     */
    private function fetchMeta(string $baseUrl): array
    {
        $path = (string) config('source.meta_path', '/_meta.json');
        $url = rtrim($baseUrl, '/').$path;

        try {
            $response = Http::connectTimeout(SourceHealthCheck::TIMEOUT_SECONDS)
                ->timeout(SourceHealthCheck::TIMEOUT_SECONDS)
                ->get($url);
        } catch (ConnectionException|Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        $allowed = ['covers_url', 'report_url', 'status_family', 'static_json'];

        return array_intersect_key($body, array_flip($allowed));
    }

    /**
     * Setup's one-field version-- the URL is the only thing asked
     * for, health-checked the same way. A URL that matches the built-in source's own API just enables the
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
            $this->setEnabled(HttpSource::KEY, true);
            $this->setBlocked(HttpSource::KEY, false);

            return $this->find(HttpSource::KEY);
        }

        $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;

        return $this->add($host, $baseUrl);
    }

    /**
     * Changes a user source's URL, health-checked the same way as `add()`. The built-in
     * source's URL is the app's real, fixed API config — not user-editable through
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
        $source->update(['config' => ['base_url' => $baseUrl, 'meta' => $this->fetchMeta($baseUrl)]]);

        AppServiceProvider::activateUserSource();

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
