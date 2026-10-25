<?php

namespace App\Adapters\Http\RateLimit;

final readonly class Call
{
    public string $method;

    public function __construct(string $method, public string $url)
    {
        $this->method = strtoupper($method);
    }

    public static function get(string $url): self
    {
        return new self('GET', $url);
    }

    public function host(): string
    {
        return strtolower((string) parse_url($this->url, PHP_URL_HOST));
    }

    public function path(): string
    {
        return '/'.trim((string) parse_url($this->url, PHP_URL_PATH), '/');
    }
}
