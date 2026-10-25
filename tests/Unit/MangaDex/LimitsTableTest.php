<?php

use App\Adapters\Http\RateLimit\Call;
use App\Adapters\Http\RateLimit\LimitsTable;
use App\Adapters\Http\RateLimit\Priority;

beforeEach(fn () => $this->limits = LimitsTable::fromConfig());

it('puts every api.mangadex.org call in the global bucket and the mangadex.org family', function () {
    $r = $this->limits->resolve(Call::get('https://api.mangadex.org/manga?title=x'), Priority::Interactive);

    expect($r->buckets)->toBe(['global'])
        ->and($r->endpoint)->toBeNull()
        ->and($r->family)->toBe('mangadex.org');
});

it('adds the endpoint bucket for limited endpoints', function (string $method, string $url, string $bucket) {
    $r = $this->limits->resolve(new Call($method, $url), Priority::Interactive);

    expect($r->buckets)->toBe(['global', $bucket])->and($r->endpoint)->toBe($bucket);
})->with([
    ['GET', 'https://api.mangadex.org/at-home/server/a1b2c3', 'at-home'],
    ['GET', 'https://api.mangadex.org/manga/random', 'random'],
    ['POST', 'https://api.mangadex.org/auth/login', 'auth-login'],
    ['POST', 'https://api.mangadex.org/chapter/abc/read', 'chapter-read'],
    ['POST', 'https://api.mangadex.org/manga/draft/abc/commit', 'manga-draft-commit'],
    ['POST', 'https://api.mangadex.org/upload/begin/abc', 'upload-begin'],
    ['DELETE', 'https://api.mangadex.org/upload/abc/batch', 'upload-files'],
    ['DELETE', 'https://api.mangadex.org/upload/abc/def', 'upload-files'],
    ['DELETE', 'https://api.mangadex.org/upload/abc', 'upload-abandon'],
]);

it('does not treat GET /manga/{id} as a write endpoint', function () {
    $r = $this->limits->resolve(Call::get('https://api.mangadex.org/manga/abc'), Priority::Interactive);

    expect($r->buckets)->toBe(['global']);
});

it('adds the background buckets for prefetch and bulk', function (Priority $priority) {
    $r = $this->limits->resolve(Call::get('https://api.mangadex.org/at-home/server/abc'), $priority);

    expect($r->buckets)->toBe(['global', 'at-home', 'global:background', 'at-home:background']);
})->with([Priority::Prefetch, Priority::Bulk]);

it('maps the other hosts', function (string $url, array $buckets, ?string $family) {
    $r = $this->limits->resolve(Call::get($url), Priority::Interactive);

    expect($r->buckets)->toBe($buckets)->and($r->family)->toBe($family);
})->with([
    'covers' => ['https://uploads.mangadex.org/covers/m/c.jpg.256.jpg', ['covers'], 'mangadex.org'],
    'report' => ['https://api.mangadex.network/report', ['report'], null],
    '@Home server' => ['https://abc.xyz.mangadex.network:443/token/data/hash/1.png', ['images'], null],
]);

it('scales a learned limit by the configured budget ratio', function () {
    expect($this->limits->bucket('at-home')->capacityFor(40))->toBe(38.0)
        ->and($this->limits->bucket('at-home')->capacityFor(20))->toBe(19.0)
        ->and($this->limits->bucket('global')->refillPerSec())->toBe(4.0)
        ->and($this->limits->bucket('at-home')->refillPerSec())->toEqualWithDelta(38 / 60, 0.0001);
});
