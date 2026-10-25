<?php

use App\Models\Chapter;
use App\Models\ChapterFeed;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\StoredChapter;
use App\Repositories\ChapterRepository;
use App\Repositories\MangaRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\SourceFixtures;

const FEED_OPM = 'd8a959f7-648e-4c8d-8f23-f1f3f8e129f3';
const FEED_CSM = 'a77742b1-befd-49a4-bff5-1ad4e6b0ef7b';
const FEED_ONESHOT = '69141247-f88f-4149-a994-31612cb3eb74';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    $this->settings = app(SettingsRepository::class);
    $this->chapters = app(ChapterRepository::class);
});

/** Chapter numbers in the order given, null as "Oneshot". */
function chapterNumbers(iterable $chapters): array
{
    return collect($chapters)->map(fn (Chapter $c) => $c->number ?? 'Oneshot')->all();
}

/** A feed page built from the recorded OPM chapter, `$count` chapters numbered from `$from`. */
function syntheticFeedPage(int $from, int $count, int $total): array
{
    $template = SourceFixtures::body('feed-one-punch-es-la')['data'][0];
    $data = [];

    for ($n = $from; $n < $from + $count; $n++) {
        $chapter = $template;
        $chapter['id'] = (string) Str::uuid();
        $chapter['attributes']['chapter'] = (string) ($n + 1);
        $chapter['attributes']['volume'] = null;
        $data[] = $chapter;
    }

    return ['result' => 'ok', 'response' => 'collection', 'data' => $data, 'limit' => 500, 'offset' => $from, 'total' => $total];
}

function fakeFeedAgain(Closure $stub): void
{
    Http::swap(new Factory(app('events')));
    Http::fake($stub);
}

it('sorts decimal numbers naturally and puts "No volume yet" last (Review Focus 3)', function () {
    SourceFixtures::fake('feed-one-punch-es-la');

    $this->chapters->refresh(FEED_OPM, 'es-la');
    $sections = $this->chapters->sections(FEED_OPM, 'es-la');

    $volumes = array_column($sections, 'volume');
    expect($volumes[0])->toBe('3')
        ->and(end($volumes))->toBeNull()
        ->and(array_slice($volumes, 0, -1))->toBe(collect($volumes)->filter()->sort(strnatcmp(...))->values()->all())
        ->and(chapterNumbers($sections[0]['chapters']))->toBe(['20', '20.2']);

    $numbers = collect($this->chapters->get(FEED_OPM, 'es-la')->data)->pluck('number')->unique()->values();
    expect($numbers->search('126.2'))->toBe($numbers->search('126') + 1)
        ->and($numbers->all())->toBe($numbers->sort(strnatcmp(...))->values()->all());
});

it('saves the feed with its total and answers fresh for an hour, then stale', function () {
    SourceFixtures::fake('feed-one-punch-es-la');

    $this->chapters->refresh(FEED_OPM, 'es-la');
    $result = $this->chapters->get(FEED_OPM, 'es-la');

    expect($result->data)->toHaveCount(182)
        ->and($result->isStale)->toBeFalse()
        ->and(ChapterFeed::where('manga_id', FEED_OPM)->where('language', 'es-la')->value('total'))->toBe(182)
        ->and($result->data->first()->group_name)->toBe('Ichi no Fansub');

    Carbon::setTestNow('2026-10-24 13:00:00');
    expect($this->chapters->get(FEED_OPM, 'es-la')->isStale)->toBeTrue();
});

it('uses the 15-minute age for a series in the Library', function () {
    SourceFixtures::fake('feed-one-punch-es-la');
    $this->chapters->refresh(FEED_OPM, 'es-la');
    LibraryEntry::create(['manga_id' => FEED_OPM, 'created_at' => Carbon::now()]);

    Carbon::setTestNow('2026-10-24 12:15:00');

    expect($this->chapters->get(FEED_OPM, 'es-la')->isStale)->toBeTrue();
});

it('shows a null number as Oneshot without a volume', function () {
    SourceFixtures::fake('feed-oneshot-en');

    $this->chapters->refresh(FEED_ONESHOT, 'en');
    $sections = $this->chapters->sections(FEED_ONESHOT, 'en');

    expect($sections)->toHaveCount(1)
        ->and($sections[0]['volume'])->toBeNull()
        ->and(chapterNumbers($sections[0]['chapters']))->toBe(['Oneshot']);
});

it('keeps external chapters with their link and orders duplicate uploads by group', function () {
    SourceFixtures::fake('feed-chainsaw-man-en');

    $this->chapters->refresh(FEED_CSM, 'en');
    $chapters = $this->chapters->get(FEED_CSM, 'en')->data;

    $external = $chapters->whereNotNull('external_url');
    expect($chapters)->toHaveCount(108)
        ->and($external)->toHaveCount(10)
        ->and($external->every(fn (Chapter $c) => str_starts_with($c->external_url, 'https://')))->toBeTrue();

    $first = $chapters->where('number', '1')->pluck('group_name')->all();
    expect($first)->toHaveCount(3)
        ->and($first)->toBe(collect($first)->sort()->values()->all());
});

it('puts every upload of a number into the volume one of them has', function () {
    $page = SourceFixtures::body('feed-one-punch-es-la');
    $late = $page['data'][0];
    $late['id'] = (string) Str::uuid();
    $late['attributes']['volume'] = null;
    $late['relationships'][0]['attributes']['name'] = 'Zeta Scans';
    $page['data'][] = $late;
    $page['total']++;
    fakeFeedAgain(fn () => Http::response($page));

    $this->chapters->refresh(FEED_OPM, 'es-la');
    $sections = collect($this->chapters->sections(FEED_OPM, 'es-la'));

    expect($sections->firstWhere('volume', '3')['chapters']->where('number', '20')->pluck('group_name')->all())
        ->toBe(['Ichi no Fansub', 'Zeta Scans'])
        ->and($sections->firstWhere('volume', null)['chapters']->where('number', '20'))->toBeEmpty();
});

it('fetches every page past 500 (offset + limit, one language)', function () {
    fakeFeedAgain(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(syntheticFeedPage((int) $query['offset'], min(500, 1203 - (int) $query['offset']), 1203));
    });

    $this->chapters->refresh(FEED_OPM, 'es-la');

    $offsets = collect(Http::recorded())->map(fn (array $pair) => parse_url($pair[0]->url(), PHP_URL_QUERY))
        ->map(function (string $q) {
            parse_str($q, $query);

            return [(int) $query['offset'], $query['translatedLanguage']];
        })->all();

    expect($offsets)->toBe([[0, ['es-la']], [500, ['es-la']], [1000, ['es-la']]])
        ->and($this->chapters->get(FEED_OPM, 'es-la')->data)->toHaveCount(1203);
});

it('keeps the saved feed when a page fails, marked stale later (Review Focus 4)', function () {
    SourceFixtures::fake('feed-one-punch-es-la');
    $this->chapters->refresh(FEED_OPM, 'es-la');

    $calls = 0;
    fakeFeedAgain(function () use (&$calls) {
        return ++$calls === 1 ? Http::response(syntheticFeedPage(0, 500, 900)) : throw new ConnectionException('offline');
    });
    Carbon::setTestNow('2026-10-24 14:00:00');

    expect(fn () => $this->chapters->refresh(FEED_OPM, 'es-la'))->toThrow(ConnectionException::class);

    $result = $this->chapters->get(FEED_OPM, 'es-la');
    expect($result->data)->toHaveCount(182)
        ->and($result->isStale)->toBeTrue()
        ->and($result->fetchedAt->toDateTimeString())->toBe('2026-10-24 12:00:00');
});

it('drops chapters gone from MangaDex, but a stored one stays for the Library', function () {
    SourceFixtures::fake('feed-one-punch-es-la');
    $this->chapters->refresh(FEED_OPM, 'es-la');
    [$stored, $dropped] = Chapter::where('manga_id', FEED_OPM)->orderBy('id')->limit(2)->pluck('id')->all();
    StoredChapter::create(['chapter_id' => $stored, 'tier' => 'icloud', 'quality' => 'data-saver', 'status' => 'done']);

    $page = SourceFixtures::body('feed-one-punch-es-la');
    $page['data'] = array_values(array_filter($page['data'], fn (array $c) => ! in_array($c['id'], [$stored, $dropped], true)));
    $page['total'] = 180;
    fakeFeedAgain(fn () => Http::response($page));
    Carbon::setTestNow('2026-10-24 12:00:10'); // past the client's 5 s shared-GET window

    $this->chapters->refresh(FEED_OPM, 'es-la');

    expect($this->chapters->get(FEED_OPM, 'es-la')->data)->toHaveCount(180)
        ->and(Chapter::find($dropped))->toBeNull()
        ->and(Chapter::find($stored))->not->toBeNull()
        ->and(Chapter::find($stored)->fetched_at)->toBeNull();
});

it('keeps each language on its own', function () {
    SourceFixtures::fake('feed-one-punch-es-la');
    $this->chapters->refresh(FEED_OPM, 'es-la');

    expect($this->chapters->get(FEED_OPM, 'en')->isEmpty())->toBeTrue()
        ->and($this->chapters->get(FEED_OPM, 'en')->isStale)->toBeTrue()
        ->and($this->chapters->get(FEED_OPM, 'es-la')->data)->toHaveCount(182);
});

it('sends every content rating and the group include', function () {
    SourceFixtures::fake('feed-one-punch-es-la');

    $this->chapters->refresh(FEED_OPM, 'es-la');

    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'contentRating[]=pornographic')
        && str_contains(urldecode($request->url()), 'includes[]=scanlation_group'));
});

it('opens the chapter list in the first chain language and lists the chain first', function () {
    SourceFixtures::fake('manga-ids');
    app(MangaRepository::class)->refreshByIds([FEED_OPM, '8a45c930-32d1-423e-bffd-9e8d71312134']);
    $this->settings->setLanguage('es-la');

    $languages = $this->chapters->languages(FEED_OPM);

    expect($this->chapters->defaultLanguage(FEED_OPM))->toBe('es-la')
        ->and(array_slice($languages, 0, 3))->toBe(['es-la', 'es', 'en'])
        ->and(array_slice($languages, 3))->toBe(collect($languages)->slice(3)->sort()->values()->all());

    $this->settings->setLanguage('ja');
    expect($this->chapters->defaultLanguage(FEED_OPM))->toBe('en');
});

it('leaves out a language whose feed came back empty until that answer goes stale', function () {
    $this->settings->setLanguage('es-la');
    Manga::query()->create([
        'id' => FEED_OPM, 'title' => ['en' => 'Seeded'], 'alt_titles' => [], 'description' => [], 'status' => 'ongoing', 'year' => 2020,
        'content_rating' => 'safe', 'available_languages' => ['es-la', 'en', 'fr'], 'original_language' => 'ja', 'tag_ids' => [],
        'cover_file' => null, 'authors' => [], 'artists' => [], 'fetched_at' => Carbon::now(),
    ]);
    ChapterFeed::query()->insert([
        ['manga_id' => FEED_OPM, 'language' => 'es-la', 'total' => 0, 'fetched_at' => Carbon::now()],
        ['manga_id' => FEED_OPM, 'language' => 'en', 'total' => 1, 'fetched_at' => Carbon::now()],
    ]);
    Chapter::query()->create([
        'id' => 'en-1', 'manga_id' => FEED_OPM, 'volume' => null, 'number' => '1', 'title' => null, 'language' => 'en',
        'group_id' => null, 'group_name' => null, 'pages' => 20, 'external_url' => null, 'readable_at' => Carbon::now(), 'fetched_at' => Carbon::now(),
    ]);

    expect($this->chapters->languages(FEED_OPM))->toBe(['en', 'fr'])
        ->and($this->chapters->defaultLanguage(FEED_OPM))->toBe('en');

    Carbon::setTestNow(Carbon::now()->addSeconds(config('readex.refresh_ages')['feed']));

    expect($this->chapters->languages(FEED_OPM))->toBe(['es-la', 'en', 'fr'])
        ->and($this->chapters->defaultLanguage(FEED_OPM))->toBe('es-la');
});

it('answers from SQLite only', function () {
    fakeFeedAgain(fn () => throw new RuntimeException('no network expected'));

    expect($this->chapters->get(FEED_OPM, 'es-la')->isEmpty())->toBeTrue()
        ->and($this->chapters->sections(FEED_OPM, 'es-la'))->toBe([]);
});
