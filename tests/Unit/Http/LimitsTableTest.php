<?php

use App\Adapters\Http\RateLimit\Call;
use App\Adapters\Http\RateLimit\LimitsTable;
use App\Adapters\Http\RateLimit\Priority;
use App\Repositories\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(SettingsRepository::class)->setSourceUrl('https://api.source.test');
    $this->limits = LimitsTable::fromConfig();
});

it('puts every /api call in the global bucket + family keyed by source host', function () {
    $r = $this->limits->resolve(Call::get('https://api.source.test/api/manga?title=x'), Priority::Interactive);

    expect($r->buckets)->toBe(['global'])
        ->and($r->endpoint)->toBeNull()
        ->and($r->family)->toBe('api.source.test');
});

it('adds the endpoint bucket for limited endpoints', function (string $method, string $url, string $bucket) {
    $r = $this->limits->resolve(new Call($method, $url), Priority::Interactive);

    expect($r->buckets)->toBe(['global', $bucket])->and($r->endpoint)->toBe($bucket);
})->with([
    ['GET', 'https://api.source.test/api/at-home/server/a1b2c3', 'at-home'],
    ['GET', 'https://api.source.test/api/manga/random', 'random'],
    ['POST', 'https://api.source.test/api/auth/login', 'auth-login'],
    ['POST', 'https://api.source.test/api/chapter/abc/read', 'chapter-read'],
    ['POST', 'https://api.source.test/api/manga/draft/abc/commit', 'manga-draft-commit'],
    ['POST', 'https://api.source.test/api/upload/begin/abc', 'upload-begin'],
    ['DELETE', 'https://api.source.test/api/upload/abc/batch', 'upload-files'],
    ['DELETE', 'https://api.source.test/api/upload/abc/def', 'upload-files'],
    ['DELETE', 'https://api.source.test/api/upload/abc', 'upload-abandon'],
]);

it('does not treat GET /api/manga/{id} as a write endpoint', function () {
    $r = $this->limits->resolve(Call::get('https://api.source.test/api/manga/abc'), Priority::Interactive);

    expect($r->buckets)->toBe(['global']);
});

it('adds the background buckets for prefetch and bulk', function (Priority $priority) {
    $r = $this->limits->resolve(Call::get('https://api.source.test/api/at-home/server/abc'), $priority);

    expect($r->buckets)->toBe(['global', 'at-home', 'global:background', 'at-home:background']);
})->with([Priority::Prefetch, Priority::Bulk]);

it('maps same-origin covers and report via path rules + off-source @Home images via *', function (string $method, string $url, array $buckets, ?string $family) {
    $r = $this->limits->resolve(new Call($method, $url), Priority::Interactive);

    expect($r->buckets)->toBe($buckets)->and($r->family)->toBe($family);
})->with([
    'covers' => ['GET', 'https://api.source.test/covers/m/c.jpg.256.jpg', ['covers'], 'api.source.test'],
    'report' => ['POST', 'https://api.source.test/report', ['report'], 'api.source.test'],
    '@Home server' => ['GET', 'https://abc.xyz.source.test:443/token/data/hash/1.png', ['images'], null],
]);

it('scales a learned limit by the configured budget ratio', function () {
    expect($this->limits->bucket('at-home')->capacityFor(40))->toBe(38.0)
        ->and($this->limits->bucket('at-home')->capacityFor(20))->toBe(19.0)
        ->and($this->limits->bucket('global')->refillPerSec())->toBe(4.0)
        ->and($this->limits->bucket('at-home')->refillPerSec())->toEqualWithDelta(38 / 60, 0.0001);
});
