<?php

namespace App\Adapters\Http\RateLimit;

final readonly class Resolution
{
    /**
     * @param  list<string>  $buckets  every bucket the call takes a token from
     * @param  string|null  $endpoint  bucket that receives X-RateLimit-* header sync
     * @param  string|null  $family  blocked as a whole on 429 / 403 ban
     */
    public function __construct(
        public array $buckets,
        public ?string $endpoint,
        public ?string $family,
    ) {}
}
