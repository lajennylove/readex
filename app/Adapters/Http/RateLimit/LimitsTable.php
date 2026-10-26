<?php

namespace App\Adapters\Http\RateLimit;

use App\Sources\ActiveSource;
use InvalidArgumentException;

final class LimitsTable
{
    /** @var array<string, BucketDefinition> */
    private array $buckets = [];

    /** @var list<array{method: string, regex: string, bucket: string}> */
    private array $rules = [];

    /** @param  array{buckets: array, rules: array, hosts: array, background: array}  $config */
    public function __construct(private readonly array $config)
    {
        foreach ($config['buckets'] as $key => $b) {
            $this->buckets[$key] = new BucketDefinition(
                key: $key,
                capacity: (float) $b['capacity'],
                perSeconds: (float) $b['per_seconds'],
                limit: $b['limit'] ?? null,
                adaptive: $b['adaptive'] ?? false,
            );
        }

        foreach ($config['rules'] as [$method, $path, $bucket]) {
            $regex = '#^'.str_replace(preg_quote('{id}', '#'), '[^/]+', preg_quote($path, '#')).'$#';
            $this->rules[] = ['method' => $method, 'regex' => $regex, 'bucket' => $bucket];
        }
    }

    public static function fromConfig(): self
    {
        return new self(config('source-limits'));
    }

    public function resolve(Call $call, Priority $priority): Resolution
    {
        [$hostBucket, $family] = $this->resolveHost($call->host());

        $buckets = [$hostBucket];
        $endpoint = null;

        if ($hostBucket === 'global') {
            foreach ($this->rules as $rule) {
                if ($rule['method'] === $call->method && preg_match($rule['regex'], $call->path())) {
                    $endpoint = $rule['bucket'];
                    // feature/0035: covers and report are same-origin now; keep
                    // them exclusive to their own bucket (they used to live on
                    // separate hosts). Everything else layers endpoint on top
                    // of global.
                    $buckets = in_array($endpoint, ['covers', 'report'], true) ? [$endpoint] : [$hostBucket, $endpoint];
                    break;
                }
            }
        }

        if ($priority->isBackground()) {
            foreach ($buckets as $key) {
                if (isset($this->config['background'][$key])) {
                    $buckets[] = $this->config['background'][$key];
                }
            }
        }

        return new Resolution($buckets, $endpoint, $family);
    }

    /**
     * ReaDex is agnostic — the API/covers/report host is whatever the user
     * configured in Setup. Match the URL's host against the active source's
     * host at runtime; a match means "primary source traffic" (global bucket
     * + path rules), anything else falls to '*' (off-source @Home images).
     *
     * @return array{0: string, 1: ?string}
     */
    private function resolveHost(string $host): array
    {
        $active = app(ActiveSource::class);

        if ($active->isConfigured()) {
            $sourceHost = parse_url($active->baseUrl(), PHP_URL_HOST) ?: '';

            if ($host === $sourceHost) {
                // Family tracks per-source ban state so a 429/403 against one
                // configured source doesn't bleed over if the user swaps sources.
                return ['global', $sourceHost];
            }
        }

        return $this->config['hosts']['*'];
    }

    public function bucket(string $key): BucketDefinition
    {
        if (str_starts_with($key, 'family:')) {
            return new BucketDefinition($key, 0.0, 0.0);
        }

        return $this->buckets[$key] ?? throw new InvalidArgumentException("Unknown rate bucket [{$key}]");
    }

    /** @return list<BucketDefinition> */
    public function adaptiveBuckets(): array
    {
        return array_values(array_filter($this->buckets, fn (BucketDefinition $b) => $b->adaptive));
    }
}
