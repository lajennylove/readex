<?php

use App\MangaDex\RateLimit\Priority;
use App\Repositories\SettingsRepository;
use App\Sources\MangaDexSource;
use App\Sources\Source;
use App\Sources\SourceRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Tests\Support\MangaDexFixtures;

const SRC_OPM = 'd8a959f7-648e-4c8d-8f23-f1f3f8e129f3';
const SRC_AT_HOME = '4e01bda4-0a04-4cdd-b3a9-edcafa7bbfd4';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    config(['readex.page_sizes.list' => 10]);
    $this->settings = app(SettingsRepository::class);
    $this->source = app(MangaDexSource::class);
});

it('implements the generic Source interface', function () {
    expect($this->source)->toBeInstanceOf(Source::class)
        ->and($this->source->key())->toBe('mangadex')
        ->and($this->source->label())->toBe('MangaDex')
        ->and($this->source->isBuiltIn())->toBeTrue();
});

it('registers MangaDex as the only source and finds it by key', function () {
    $registry = app(SourceRegistry::class);

    expect($registry->all())->toHaveCount(1)
        ->and($registry->all()[0])->toBeInstanceOf(MangaDexSource::class)
        ->and($registry->find('mangadex'))->toBeInstanceOf(MangaDexSource::class)
        ->and($registry->find('mangadex'))->toBe($registry->mangaDex())
        ->and($registry->find('unknown'))->toBeNull();
});

it('refreshes and reads search results through the adapter, same shape as MangaRepository', function () {
    MangaDexFixtures::fake('search-one-punch-p1');

    expect($this->source->refreshSearch('one punch'))->toBeTrue();
    $result = $this->source->search('one punch');

    expect($result->isStale)->toBeFalse()
        ->and($result->data)->not->toBeEmpty()
        ->and($result->data[0]->id)->toBeString();
});

it('loads more search results through the adapter, appended after the first page', function () {
    MangaDexFixtures::fake('search-one-punch-p1', 'search-one-punch-p2');
    $this->source->refreshSearch('one punch');
    $before = $this->source->search('one punch')->data->count();

    $this->source->loadMore('one punch');

    expect($this->source->search('one punch')->data->count())->toBeGreaterThan($before);
});

it('refreshes and reads a chapter feed through the adapter, same shape as ChapterRepository', function () {
    MangaDexFixtures::fake('feed-one-punch-es-la');

    $this->source->refreshChapterFeed(SRC_OPM, 'es-la');
    $result = $this->source->chapterFeed(SRC_OPM, 'es-la');

    expect($result->isStale)->toBeFalse()
        ->and($result->data)->not->toBeEmpty();
});

it('gives page URLs through the adapter from an @Home lookup', function () {
    MangaDexFixtures::fake('at-home-oneshot');

    $urls = $this->source->pageUrls(SRC_AT_HOME, 'data-saver', Priority::Interactive);

    expect($urls)->toBeArray()->not->toBeEmpty()
        ->and($urls[0])->toContain($urls[0]);
});
