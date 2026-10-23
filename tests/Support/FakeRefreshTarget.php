<?php

namespace Tests\Support;

use App\MangaDex\RateLimit\Priority;
use App\Repositories\Freshness;
use App\Repositories\Result;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/** A repository whose refresh() follows a script: each entry is a Throwable to throw or a list of items to store. */
final class FakeRefreshTarget
{
    public static array $items = [];

    public static ?CarbonImmutable $fetchedAt = null;

    /** @var list<array{0: string, 1: Priority}> */
    public static array $calls = [];

    /** @var list<Throwable|array> */
    public static array $script = [];

    public static function reset(array $items = [], ?CarbonImmutable $fetchedAt = null): void
    {
        self::$items = $items;
        self::$fetchedAt = $fetchedAt;
        self::$calls = [];
        self::$script = [];
    }

    public function get(): Result
    {
        return Freshness::result(self::$items, self::$fetchedAt, 'home.latest');
    }

    public function refresh(string $row, Priority $priority = Priority::Interactive): void
    {
        self::$calls[] = [$row, $priority];
        $next = array_shift(self::$script) ?? ['fresh'];

        if ($next instanceof Throwable) {
            throw $next;
        }

        self::$items = $next;
        self::$fetchedAt = CarbonImmutable::instance(Carbon::now());
    }
}
