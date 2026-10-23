<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class Freshness
{
    /** `$kind` is a key of `readex.refresh_ages`; keys contain dots, so never `config("readex.refresh_ages.$kind")`. */
    public static function isStale(?CarbonInterface $fetchedAt, string $kind): bool
    {
        $ages = config('readex.refresh_ages');

        if (! array_key_exists($kind, $ages)) {
            throw new InvalidArgumentException("Unknown refresh age [{$kind}].");
        }

        if ($fetchedAt === null) {
            return true;
        }

        return Carbon::now()->getTimestamp() - $fetchedAt->getTimestamp() >= $ages[$kind];
    }

    public static function result(mixed $data, ?CarbonInterface $fetchedAt, string $kind): Result
    {
        return new Result(
            $data,
            $fetchedAt === null ? null : CarbonImmutable::instance($fetchedAt),
            self::isStale($fetchedAt, $kind),
        );
    }
}
