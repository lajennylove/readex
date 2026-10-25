<?php

namespace App\Adapters\Http\RateLimit;

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
        [$hostBucket, $family] = $this->config['hosts'][$call->host()] ?? $this->config['hosts']['*'];

        $buckets = [$hostBucket];
        $endpoint = null;

        if ($hostBucket === 'global') {
            foreach ($this->rules as $rule) {
                if ($rule['method'] === $call->method && preg_match($rule['regex'], $call->path())) {
                    $endpoint = $rule['bucket'];
                    $buckets[] = $endpoint;
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
