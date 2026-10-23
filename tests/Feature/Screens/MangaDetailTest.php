<?php

use App\Language\Languages;
use App\Models\Chapter;
use App\Models\ChapterFeed;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\Statistic;
use App\NativeComponents\MangaDetail;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\MangaDexFixtures;

const DETAIL_RTW = 'aafb046c-9de4-4c3d-990c-6d75b079c0b9';
const DETAIL_NOVA = '8a45c930-32d1-423e-bffd-9e8d71312134';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    Covers::useTempCaches();
    app(SettingsRepository::class)->setLanguage('es-la');
    $this->async = AsyncTask::fake();
    Queue::fake();
    $this->asked = [];
    $fixtures = MangaDexFixtures::stub('manga-rtw', 'manga-nova', 'statistics-rtw', 'feed-rtw-es-la-p1', 'feed-rtw-es-la-p2');
    Http::fake(function (Request $request) use ($fixtures) {
        $this->asked[] = rawurldecode($request->url());

        return $fixtures($request);
    });
});

afterEach(fn () => AsyncTask::clearFake());

/** Every text in the last render, in screen order. */
function detailTexts(array $node): array
{
    $texts = ($node['type'] ?? null) === 'text' ? [$node['props']['text']] : [];

    foreach ($node['children'] ?? [] as $child) {
        $texts = [...$texts, ...detailTexts($child)];
    }

    return $texts;
}

/** @return list<string> labels of every button */
function detailButtons(array $node): array
{
    $labels = ($node['type'] ?? null) === 'button' ? [$node['props']['label'] ?? ''] : [];

    foreach ($node['children'] ?? [] as $child) {
        $labels = [...$labels, ...detailButtons($child)];
    }

    return $labels;
}

function detailChapter(string $id, string $mangaId, ?string $number, int $pages = 20): Chapter
{
    return Chapter::query()->create([
        'id' => $id, 'manga_id' => $mangaId, 'volume' => null, 'number' => $number, 'title' => null,
        'language' => 'es-la', 'group_id' => null, 'group_name' => null, 'pages' => $pages,
        'external_url' => null, 'readable_at' => Carbon::now(), 'fetched_at' => Carbon::now(),
    ]);
}

it('loads a never-opened series and shows its header, stats, languages and description', function () {
    $screen = Native::visit('/search/manga/'.DETAIL_RTW)->assertScreen(MangaDetail::class);
    $texts = detailTexts($screen->tree());
    $chain = app(SettingsRepository::class)->chain();
    $languages = array_map(Languages::name(...), ['es-la', 'es', 'en', 'fr', 'id', 'pl', 'pt-br', 'ru', 'tr', 'uk']);

    expect(collect($this->asked)->filter(fn (string $url) => str_contains($url, '/manga/'.DETAIL_RTW.'?'))->values()->all())
        ->toHaveCount(1)
        ->and(collect($this->asked)->filter(fn (string $url) => str_contains($url, '/statistics/manga/'.DETAIL_RTW))->all())->toHaveCount(1)
        ->and($texts)->toContain('Story: Er Mu (二目)')
        ->toContain('Art: Yuewen Manhua (阅文漫画), Dr. Woodman')
        ->toContain('Completed · 2018')
        ->toContain('★ 8.32 · 40,308 follows')
        ->toContain(implode(' · ', $languages))
        ->and(collect($texts)->contains(fn (string $t) => str_starts_with($t, 'A male engineer transmigrated')))->toBeTrue();

    $manga = Manga::query()->find(DETAIL_RTW);
    $screen->assertNavTitle($chain->title($manga->title, $manga->alt_titles));
});

it('gives the header cover a fixed height so the row is as tall as the cover', function () {
    $find = function (array $node) use (&$find): ?array {
        if (($node['type'] ?? null) === 'row') {
            return $node;
        }

        foreach ($node['children'] ?? [] as $child) {
            if ($found = $find($child)) {
                return $found;
            }
        }

        return null;
    };

    $cover = $find(Native::visit('/search/manga/'.DETAIL_RTW)->tree())['children'][0];

    expect($cover['type'])->toBe('stack')
        ->and($cover['layout']['width'])->toEqual(128)
        ->and($cover['layout']['height'])->toEqual(192);
});

it('says "Story & art" when the author also drew it', function () {
    $texts = detailTexts(Native::visit('/home/manga/'.DETAIL_NOVA)->tree());

    expect($texts)->toContain('Story & art: Clevercool')
        ->and(collect($texts)->contains(fn (string $t) => str_starts_with($t, 'Art:')))->toBeFalse()
        ->and($texts)->toContain('Ongoing · 2026');
});

it('asks for the detail of a series only saved by a list call (no author yet)', function () {
    Covers::manga(DETAIL_RTW, title: 'From a list');
    Manga::query()->whereKey(DETAIL_RTW)->update(['fetched_at' => Carbon::now()]);

    $texts = detailTexts(Native::visit('/search/manga/'.DETAIL_RTW)->tree());

    expect(collect($this->asked)->filter(fn (string $url) => str_contains($url, '/manga/'.DETAIL_RTW.'?'))->all())->toHaveCount(1)
        ->and($texts)->toContain('Story: Er Mu (二目)');
});

it('shows nothing but the status line when offline with nothing saved', function () {
    Http::swap(new Factory(app('events')));
    Http::fake(fn () => throw new ConnectionException('offline'));

    $screen = Native::visit('/search/manga/'.DETAIL_RTW);

    expect(detailTexts($screen->tree()))->toContain($screen->instance()->statusLine())
        ->and($screen->instance()->statusLine())->not->toBeNull()
        ->and(detailButtons($screen->tree()))->not->toContain('Favorite');
});

it('adds the series to the Library as a favorite and takes it back off', function () {
    $screen = Native::visit('/search/manga/'.DETAIL_RTW);
    expect(detailButtons($screen->tree()))->toContain('Favorite');

    $screen->tap('Favorite');
    expect(LibraryEntry::query()->find(DETAIL_RTW)?->favorite)->toBeTrue()
        ->and(detailButtons($screen->tree()))->toContain('Favorited')->not->toContain('Favorite');

    $screen->tap('Favorited');
    expect(LibraryEntry::query()->find(DETAIL_RTW)?->favorite)->toBeFalse()
        ->and(detailButtons($screen->tree()))->toContain('Favorite');
});

it('has no Continue button for a series never read', function () {
    expect(collect(detailButtons(Native::visit('/search/manga/'.DETAIL_RTW)->tree()))->filter(fn (string $l) => str_starts_with($l, 'Continue'))->all())->toBe([]);
});

it('continues where the reader left off, on the saved page shown from 1', function () {
    Native::visit('/library/manga/'.DETAIL_RTW);
    detailChapter('c-12', DETAIL_RTW, '12');
    detailChapter('c-13', DETAIL_RTW, '13');
    app(LibraryRepository::class)->saveProgress('c-12', 4, 20);

    $screen = Native::visit('/library/manga/'.DETAIL_RTW);
    expect(detailButtons($screen->tree()))->toContain('Continue · Ch. 12, page 5');

    $screen->tap('Continue · Ch. 12, page 5')
        ->assertNativeCalled('Dialog.Toast', fn (array $p) => $p['message'] === 'Reader comes in the next phase');
});

it('continues with the next chapter once the last one read is finished', function () {
    Native::visit('/library/manga/'.DETAIL_RTW);
    detailChapter('c-12', DETAIL_RTW, '12');
    detailChapter('c-13', DETAIL_RTW, '13');
    app(LibraryRepository::class)->saveProgress('c-12', 19, 20);

    expect(detailButtons(Native::visit('/library/manga/'.DETAIL_RTW)->tree()))->toContain('Continue · Ch. 13');
});

it('shows the Bayesian rating MangaDex shows, and says so when nobody rated yet', function () {
    expect(MangaDetail::stats(new Statistic(['rating_avg' => 9.4, 'rating_bayesian' => 7.05, 'follows' => 1200])))->toBe('★ 7.05 · 1,200 follows')
        ->and(MangaDetail::stats(new Statistic(['rating_avg' => null, 'rating_bayesian' => null, 'follows' => 1])))->toBe('No ratings yet · 1 follow')
        ->and(MangaDetail::stats(null))->toBeNull();
});

/** @return list<array{label: string, caption: string, current: bool, dimmed: bool}> chapter rows in screen order */
function detailRows(array $node): array
{
    if (($node['type'] ?? null) === 'pressable' && ($node['children'][0]['type'] ?? null) === 'column' && count($node['children'][0]['children'] ?? []) === 2 && isset($node['children'][0]['children'][1]['props']['text']) && isset($node['props']['a11y_label'])) {
        [$label, $caption] = $node['children'][0]['children'];

        return [[
            'label' => $label['props']['text'],
            'caption' => $caption['props']['text'],
            'current' => ($node['children'][0]['style']['bg_color'] ?? null) === '#2C2C2E',
            'dimmed' => $label['props']['color'] === $caption['props']['color'],
        ]];
    }

    $rows = [];

    foreach ($node['children'] ?? [] as $child) {
        $rows = [...$rows, ...detailRows($child)];
    }

    return $rows;
}

/** A saved series with the given languages and chapter feeds marked fresh, so the screen makes no calls. */
function detailSeries(string $id, array $languages, array $titles = ['en' => 'Seeded']): Manga
{
    $manga = Manga::query()->create([
        'id' => $id, 'title' => $titles, 'alt_titles' => [], 'description' => [], 'status' => 'ongoing', 'year' => 2020,
        'content_rating' => 'safe', 'available_languages' => $languages, 'original_language' => 'ja', 'tag_ids' => [],
        'cover_file' => null, 'authors' => ['A'], 'artists' => ['A'], 'fetched_at' => Carbon::now(),
    ]);
    Statistic::query()->create(['manga_id' => $id, 'rating_avg' => null, 'rating_bayesian' => null, 'follows' => 0, 'distribution' => [], 'fetched_at' => Carbon::now()]);

    foreach ($languages as $language) {
        ChapterFeed::query()->updateOrInsert(['manga_id' => $id, 'language' => $language], ['total' => 0, 'fetched_at' => Carbon::now()]);
    }

    return $manga;
}

function seededChapter(string $id, string $mangaId, ?string $number, array $extra = []): Chapter
{
    return Chapter::query()->create([
        'id' => $id, 'manga_id' => $mangaId, 'volume' => null, 'number' => $number, 'title' => null,
        'language' => 'es-la', 'group_id' => 'g-a', 'group_name' => 'Group A', 'pages' => 20,
        'external_url' => null, 'readable_at' => Carbon::now(), 'fetched_at' => Carbon::now(), ...$extra,
    ]);
}

const DETAIL_SEEDED = '00000000-0000-4000-8000-000000000007';

it('loads the chapter list in the reading language, volumes in order and "No volume yet" last', function () {
    $screen = Native::visit('/search/manga/'.DETAIL_RTW);
    $texts = detailTexts($screen->tree());
    $volumes = array_values(array_filter($texts, fn (string $t) => str_starts_with($t, 'Volume ') || $t === 'No volume yet'));
    $rows = detailRows($screen->tree());

    expect(collect($this->asked)->filter(fn (string $u) => str_contains($u, '/feed'))->count())->toBe(2)
        ->and(Chapter::query()->where('manga_id', DETAIL_RTW)->count())->toBe(700)
        ->and($screen->get('language'))->toBe('es-la')
        ->and($texts)->toContain('Chapters')
        ->and($volumes)->not->toBe([])
        ->and(collect($volumes)->reject('No volume yet')->values()->all())->toBe(collect($volumes)->reject('No volume yet')->sort(fn ($a, $b) => strnatcmp($a, $b))->values()->all())
        ->and(count($rows))->toBe(700)
        ->and(collect($rows)->every(fn (array $r) => ! $r['current']))->toBeTrue();

    if (in_array('No volume yet', $volumes, true)) {
        expect(end($volumes))->toBe('No volume yet');
    }
});

it('renders a 700-chapter list quickly enough to re-render on every poll', function () {
    $screen = Native::visit('/search/manga/'.DETAIL_RTW);

    $start = hrtime(true);
    $screen->call('checkWaitingRefreshes');
    $ms = (hrtime(true) - $start) / 1e6;

    expect($ms)->toBeLessThan(400);
    fwrite(STDERR, sprintf("700-chapter re-render: %.0f ms, tree %d KB\n", $ms, strlen(json_encode($screen->tree())) / 1024));
});

it('opens a Spanish reader on English when the series has no Spanish chapters (Review Focus 7)', function () {
    detailSeries(DETAIL_SEEDED, ['ja', 'en', 'fr'], ['ja-ro' => 'Romaji Title']);
    seededChapter('en-1', DETAIL_SEEDED, '1', ['language' => 'en']);

    $screen = Native::visit('/home/manga/'.DETAIL_SEEDED);

    expect($screen->get('language'))->toBe('en')
        ->and(detailRows($screen->tree()))->toHaveCount(1)
        ->and(detailRows($screen->tree())[0]['label'])->toBe('Ch. 1')
        ->and(detailTexts($screen->tree()))->toContain('Romaji Title', 'English');
});

it('moves to the next language when the opening language turns out to have no readable chapters', function () {
    detailSeries(DETAIL_SEEDED, ['es-la', 'en']);
    ChapterFeed::query()->where('manga_id', DETAIL_SEEDED)->where('language', 'es-la')->delete();
    seededChapter('en-1', DETAIL_SEEDED, '1', ['language' => 'en']);
    Http::swap(new Factory(app('events')));
    Http::fake(['*' => Http::response(['result' => 'ok', 'response' => 'collection', 'data' => [], 'limit' => 500, 'offset' => 0, 'total' => 0])]);

    $screen = Native::visit('/home/manga/'.DETAIL_SEEDED);
    $texts = detailTexts($screen->tree());

    expect(Http::recorded(fn (Request $r) => str_contains(rawurldecode($r->url()), 'translatedLanguage[]=es-la'))->count())->toBe(1)
        ->and($screen->get('language'))->toBe('en')
        ->and(detailRows($screen->tree()))->toHaveCount(1)
        ->and($texts)->toContain('English')
        ->and(collect($texts)->contains(fn (string $t) => str_contains($t, 'Español')))->toBeFalse()
        ->and(json_encode($screen->tree()))->not->toContain('pickLanguage');
});

it('switches the chapter list language and ignores a language the series lacks', function () {
    detailSeries(DETAIL_SEEDED, ['es-la', 'en']);
    seededChapter('es-1', DETAIL_SEEDED, '1');
    seededChapter('en-1', DETAIL_SEEDED, '1', ['language' => 'en', 'title' => 'Start']);
    seededChapter('en-2', DETAIL_SEEDED, '2', ['language' => 'en']);

    $screen = Native::visit('/home/manga/'.DETAIL_SEEDED);
    expect($screen->get('language'))->toBe('es-la')->and(detailRows($screen->tree()))->toHaveCount(1);

    $screen->toggle("pickLanguage('en')", true);
    expect($screen->get('language'))->toBe('en')
        ->and(array_column(detailRows($screen->tree()), 'label'))->toBe(['Ch. 1 · Start', 'Ch. 2']);

    $screen->call('pickLanguage', 'de');
    expect($screen->get('language'))->toBe('en');
});

it('has no language switch for a series in one language, and says when it has no chapters', function () {
    detailSeries(DETAIL_SEEDED, ['en']);
    Http::swap(new Factory(app('events')));
    Http::fake(['*' => Http::response(['result' => 'ok', 'response' => 'collection', 'data' => [], 'limit' => 500, 'offset' => 0, 'total' => 0])]);

    $screen = Native::visit('/home/manga/'.DETAIL_SEEDED);

    expect(json_encode($screen->tree()))->not->toContain('pickLanguage')
        ->and(detailTexts($screen->tree()))->toContain('No chapters yet');
});

it('marks read, started and up-next chapters and highlights the one Continue opens', function () {
    detailSeries(DETAIL_SEEDED, ['es-la']);
    seededChapter('c-1', DETAIL_SEEDED, '1');
    seededChapter('c-2', DETAIL_SEEDED, '2');
    seededChapter('c-3', DETAIL_SEEDED, '3');
    $library = app(LibraryRepository::class);
    $library->saveProgress('c-1', 19, 20);
    Carbon::setTestNow(Carbon::now()->addMinute());
    $library->saveProgress('c-2', 4, 20);

    $rows = detailRows(Native::visit('/library/manga/'.DETAIL_SEEDED)->tree());
    expect($rows)->toBe([
        ['label' => 'Ch. 1', 'caption' => 'Group A · Read', 'current' => false, 'dimmed' => true],
        ['label' => 'Ch. 2', 'caption' => 'Group A · Page 5 of 20', 'current' => true, 'dimmed' => false],
        ['label' => 'Ch. 3', 'caption' => 'Group A', 'current' => false, 'dimmed' => false],
    ]);

    Carbon::setTestNow(Carbon::now()->addMinute());
    $library->saveProgress('c-2', 19, 20);

    expect(detailRows(Native::visit('/library/manga/'.DETAIL_SEEDED)->tree())[2])
        ->toBe(['label' => 'Ch. 3', 'caption' => 'Group A · Up next', 'current' => true, 'dimmed' => false]);
});

it('opens the list in the language the reader is reading, not the first chain language', function () {
    detailSeries(DETAIL_SEEDED, ['es-la', 'en']);
    seededChapter('en-1', DETAIL_SEEDED, '1', ['language' => 'en']);
    app(LibraryRepository::class)->saveProgress('en-1', 3, 20);

    expect(Native::visit('/library/manga/'.DETAIL_SEEDED)->get('language'))->toBe('en');
});

it('shows every upload until the reader has a group, then one per number with theirs first', function () {
    detailSeries(DETAIL_SEEDED, ['es-la']);
    seededChapter('a-1', DETAIL_SEEDED, '1');
    seededChapter('b-1', DETAIL_SEEDED, '1', ['group_id' => 'g-b', 'group_name' => 'Group B']);
    seededChapter('b-2', DETAIL_SEEDED, '2', ['group_id' => 'g-b', 'group_name' => 'Group B']);

    expect(array_column(detailRows(Native::visit('/home/manga/'.DETAIL_SEEDED)->tree()), 'caption'))
        ->toBe(['Group A', 'Group B', 'Group B']);

    app(LibraryRepository::class)->setPreferredGroup(DETAIL_SEEDED, 'g-b');

    expect(array_column(detailRows(Native::visit('/home/manga/'.DETAIL_SEEDED)->tree()), 'caption'))
        ->toBe(['Group B', 'Group B']);
});

it('opens a licensed chapter in the browser and a readable one with the reader-later toast', function () {
    detailSeries(DETAIL_SEEDED, ['es-la']);
    seededChapter('ext-1', DETAIL_SEEDED, '1', ['group_name' => 'MangaPlus', 'external_url' => 'https://mangaplus.shueisha.co.jp/viewer/1000']);
    seededChapter('own-2', DETAIL_SEEDED, '2');

    $screen = Native::visit('/home/manga/'.DETAIL_SEEDED);
    expect(detailRows($screen->tree())[0]['caption'])->toBe('MangaPlus · Opens on mangaplus.shueisha.co.jp');

    $screen->tap('Ch. 1')
        ->assertNativeCalled('Browser.Open', fn (array $p) => $p['url'] === 'https://mangaplus.shueisha.co.jp/viewer/1000');
    $screen->tap('Ch. 2')
        ->assertNativeCalled('Dialog.Toast', fn (array $p) => $p['message'] === 'Reader comes in the next phase')
        ->assertNativeCalledTimes('Browser.Open', 1);
});
