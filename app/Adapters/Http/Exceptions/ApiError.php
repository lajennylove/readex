<?php

namespace App\Adapters\Http\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $detail = $response->json('errors.0.detail') ?? $response->json('errors.0.title') ?? $response->reason();

        return new self($response->status(), "MangaDex HTTP {$response->status()}: {$detail}");
    }
}
