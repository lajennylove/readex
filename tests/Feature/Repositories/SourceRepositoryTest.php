<?php

use App\Models\SourceConfig;
use App\Repositories\SourceRepository;
use App\Sources\MangaDexSource;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-27 12:00:00');
    $this->repo = app(SourceRepository::class);
});

it('seeds the built-in MangaDex row once, idempotently', function () {
    $this->repo->seedBuiltIn();
    $this->repo->seedBuiltIn();

    expect(SourceConfig::query()->count())->toBe(1);
    $source = SourceConfig::query()->find(MangaDexSource::KEY);

    expect($source->label)->toBe('MangaDex')
        ->and($source->kind)->toBe('builtin')
        ->and($source->enabled)->toBeTrue()
        ->and($source->blocked)->toBeFalse();
});

it('lists the built-in source first, seeding it if missing', function () {
    $sources = $this->repo->all();

    expect($sources)->toHaveCount(1)
        ->and($sources->first()->key)->toBe(MangaDexSource::KEY);
});

it('adds a user source from a generic name + URL, never pre-filled', function () {
    $source = $this->repo->add('My Source', 'https://example.test/api');

    expect($source->kind)->toBe('user')
        ->and($source->label)->toBe('My Source')
        ->and($source->config['base_url'])->toBe('https://example.test/api')
        ->and($source->enabled)->toBeTrue()
        ->and($source->blocked)->toBeFalse()
        ->and($this->repo->all())->toHaveCount(2);
});

it('rejects an empty name or an invalid URL', function () {
    expect(fn () => $this->repo->add('', 'https://example.test'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->repo->add('Name', 'not-a-url'))->toThrow(InvalidArgumentException::class);
});

it('blocks and unblocks a source locally, no reporting backend (D45 §6 item 3)', function () {
    $this->repo->seedBuiltIn();

    $this->repo->setBlocked(MangaDexSource::KEY, true);
    expect($this->repo->find(MangaDexSource::KEY)->blocked)->toBeTrue()
        ->and($this->repo->enabled())->toHaveCount(0);

    $this->repo->setBlocked(MangaDexSource::KEY, false);
    expect($this->repo->find(MangaDexSource::KEY)->blocked)->toBeFalse()
        ->and($this->repo->enabled())->toHaveCount(1);
});

it('removes a user source outright but never the built-in one', function () {
    $this->repo->seedBuiltIn();
    $user = $this->repo->add('Mine', 'https://example.test');

    $this->repo->remove(MangaDexSource::KEY);
    expect($this->repo->find(MangaDexSource::KEY))->not->toBeNull();

    $this->repo->remove($user->key);
    expect($this->repo->find($user->key))->toBeNull();
});

it('enabled() excludes blocked and disabled sources', function () {
    $this->repo->seedBuiltIn();
    $user = $this->repo->add('Mine', 'https://example.test');
    $this->repo->setEnabled($user->key, false);

    expect($this->repo->enabled()->pluck('key')->all())->toBe([MangaDexSource::KEY]);
});
