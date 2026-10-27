<?php

use App\Adapters\Http\Exceptions\RequestDeferred;
use App\Adapters\Http\RateLimit\Busy;
use App\Adapters\Http\RateLimit\BusyReason;
use App\Language\Languages;
use App\Models\Chapter;
use App\Models\ChapterFeed;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\Statistic;
use App\NativeComponents\MangaDetail;
use App\NativeComponents\Reader;
use App\Repositories\LibraryRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\SettingsRepository;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\Skeletons;
use Tests\Support\SourceFixtures;

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
    $fixtures = SourceFixtures::stub('manga-rtw', 'manga-nova', 'statistics-rtw', 'feed-rtw-es-la-p1', 'feed-rtw-es-la-p2', 'recommendations-rtw', 'recommendations-rtw-manga', 'recommendations-nova');
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

/** @return list<array{id: int, label: string, selected: bool}> every chip in screen order */
function chipNodes(array $node): array
{
    $chips = ($node['type'] ?? null) === 'chip'
        ? [['id' => $node['id'], 'label' => $node['props']['label'] ?? '', 'selected' => (bool) ($node['props']['value'] ?? false)]]
        : [];

    foreach ($node['children'] ?? [] as $child) {
        $chips = [...$chips, ...chipNodes($child)];
    }

    return $chips;
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
        ->assertNavigatedTo('/library/read/c-12')
        ->assertNativeNotCalled('Dialog.Toast');
});

it('continues with the next chapter once the last one read is finished', function () {
    Native::visit('/library/manga/'.DETAIL_RTW);
    detailChapter('c-12', DETAIL_RTW, '12');
    detailChapter('c-13', DETAIL_RTW, '13');
    app(LibraryRepository::class)->saveProgress('c-12', 19, 20);

    expect(detailButtons(Native::visit('/library/manga/'.DETAIL_RTW)->tree()))->toContain('Continue · Ch. 13');
});

it('shows the Bayesian rating the source shows, and says so when nobody rated yet', function () {
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
        'recommendations_fetched_at' => Carbon::now(),
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
        ->and(count($rows))->toBe(400)
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

/** Every empty (childless) column's height in screen order. */
function detailEmptyColumnHeights(array $node): array
{
    $heights = ($node['type'] ?? null) === 'column' && ($node['children'] ?? []) === []
        ? [(int) ($node['layout']['height'] ?? 0)]
        : [];

    foreach ($node['children'] ?? [] as $child) {
        $heights = [...$heights, ...detailEmptyColumnHeights($child)];
    }

    return $heights;
}

it('leaves a bottom pad under the chapter list so the last chapters clear the floating resume bar', function () {
    // The resume bar is ~64 px (cover h-12 + outer py-2); the spacer at the end of the
    // scroll should be 2× that so a normal scroll-up + release leaves the last chapter
    // above the bar. h-32 = 128 px.
    $screen = Native::visit('/search/manga/'.DETAIL_RTW);
    $heights = detailEmptyColumnHeights($screen->tree());

    expect($heights)->toContain(128);
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

it('opens on a readable language when the first-chain language has an empty fresh feed, but still shows its chip', function () {
    // Spec §5.7 / bugfix 0023: the tile badge is derived from `available_languages`, so every
    // language the series advertises must stay reachable from the chip row — even if its fresh
    // feed came back empty. The opening language still prefers one with readable chapters so
    // the reader lands on content, not on an empty Chapters section.
    detailSeries(DETAIL_SEEDED, ['es-la', 'en']);
    seededChapter('en-1', DETAIL_SEEDED, '1', ['language' => 'en']);
    Http::swap(new Factory(app('events')));
    Http::fake(['*' => Http::response(['result' => 'ok', 'response' => 'collection', 'data' => [], 'limit' => 500, 'offset' => 0, 'total' => 0])]);

    $screen = Native::visit('/home/manga/'.DETAIL_SEEDED);
    $chipLabels = array_column(chipNodes($screen->tree()), 'label');

    expect($screen->get('language'))->toBe('en')
        ->and(detailRows($screen->tree()))->toHaveCount(1)
        ->and($chipLabels)->toBe([Languages::name('es-la'), Languages::name('en')]);
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

it('keeps each language chip bound to one stable native node reference across renders (bug: chip multiselect after scroll)', function () {
    // Regression for the Manga Detail chip-row bug.
    //
    // Root cause (confirmed by tracing vendor/nativephp/mobile/src/Edge/Element.php
    // toArray()): a node's wire `id` is resolved by precedence: explicit
    // `nodeId`, else a hash of `native:key` (stable regardless of position),
    // else (only inside an already-keyed parent) positional index, else the
    // legacy global `$nextId++` counter. The chip row in
    // resources/views/native/manga-detail.blade.php has NO `native:key` on
    // `<native:chip>`, so each chip's id is the bare `$nextId++` counter
    // value at its position in the WHOLE tree, not scoped to the row.
    //
    // On the native (SwiftUI) side, NativeUIChipRenderer keeps its
    // "selected" state in `@State private var isSelected`, which SwiftUI
    // scopes per view the same way it scopes any other local state: by the
    // node's wire `id` -- and only re-seeds it from the server's `value`
    // prop on first appearance (`.onAppear { if !initialized { isSelected
    // = serverValue } }`). A scroll-view re-layout (from the user
    // scrolling the row) plus any unrelated re-render that changes sibling
    // count/order BEFORE the chip row (anywhere in the tree, since ids are
    // positional across the whole publish, not per-row) shifts every
    // subsequent `$nextId++` value. A chip that used to be at id N is now
    // serialized at id N+1 (or N-1), and SwiftUI matches the OLD id's
    // already-initialized view (with its own stale `isSelected`) to a
    // DIFFERENT chip's node -- the stale selection renders on the wrong
    // chip while the new pick ALSO renders selected, i.e. two (or more)
    // chips visually active.
    //
    // This can't exercise SwiftUI directly from Pest, but the wire-level
    // precondition for it IS testable and IS the defect: without a stable
    // key, a chip's `id` field changes across renders whenever something
    // else in the tree shifts -- e.g. tapping Favorite/Favorited above the
    // chip row. The fix (keying each chip by its unique, stable language
    // code) makes `id` depend only on that code, immune to any sibling
    // churn, so native view state never crosses over to a different chip.
    detailSeries(DETAIL_SEEDED, ['es-la', 'en', 'fr']);
    seededChapter('es-1', DETAIL_SEEDED, '1');
    seededChapter('en-1', DETAIL_SEEDED, '1', ['language' => 'en']);
    seededChapter('fr-1', DETAIL_SEEDED, '1', ['language' => 'fr']);

    $screen = Native::visit('/home/manga/'.DETAIL_SEEDED);

    $idsByLabel = fn () => collect(chipNodes($screen->tree()))->pluck('id', 'label')->all();

    $before = $idsByLabel();
    expect($before)->toHaveCount(3)
        ->and($before)->toHaveKeys([Languages::name('es-la'), Languages::name('en'), Languages::name('fr')]);

    // Saving reading progress makes a Continue button APPEAR above the chip
    // row on the next render -- a new sibling inserted earlier in the tree,
    // exactly the kind of upstream shape change a scrolled scroll-view's
    // own re-layout can cause before a chip tap's event lands. Without a
    // stable `native:key`, every node serialized after the insertion point
    // (including every chip) gets a DIFFERENT positional `$nextId++` value
    // than it had the frame before.
    app(LibraryRepository::class)->saveProgress('es-1', 4, 20);
    $screen->call('onResume');
    $afterContinueAppears = $idsByLabel();

    expect($afterContinueAppears)->toBe($before);

    // The real end-to-end guarantee: after picking a language across these
    // re-renders, exactly that one chip is selected -- never more than one.
    $screen->toggle("pickLanguage('en')", true);
    $chips = chipNodes($screen->tree());
    expect(collect($chips)->where('selected', true)->pluck('label')->all())
        ->toBe([Languages::name('en')]);
});

it('shows a chip for every advertised language even when only one has fetched chapters (bugfix 0023)', function () {
    // a Spanish-reading user reads and sees an "ES" badge on the tile (derived from
    // `Manga::available_languages`). Opening the series must give her a Spanish chip in the
    // Chapters tab — otherwise the badge is a lie and she can't reach her reading language.
    // Regression for bugfix/0023-detail-missing-language-chip.
    detailSeries(DETAIL_SEEDED, ['en', 'es-la']);
    seededChapter('en-1', DETAIL_SEEDED, '1', ['language' => 'en']);
    Http::swap(new Factory(app('events')));
    Http::fake(['*' => Http::response(['result' => 'ok', 'response' => 'collection', 'data' => [], 'limit' => 500, 'offset' => 0, 'total' => 0])]);

    $screen = Native::visit('/home/manga/'.DETAIL_SEEDED);
    $chipLabels = array_column(chipNodes($screen->tree()), 'label');

    // Chain order: es-la (picked) first, then en.
    expect($chipLabels)->toBe([Languages::name('es-la'), Languages::name('en')]);
});

it('tapping an advertised language with no fetched chapters triggers a feed refresh and shows the empty state (bugfix 0023)', function () {
    detailSeries(DETAIL_SEEDED, ['en', 'es-la']);
    seededChapter('en-1', DETAIL_SEEDED, '1', ['language' => 'en']);
    Http::swap(new Factory(app('events')));
    Http::fake(['*' => Http::response(['result' => 'ok', 'response' => 'collection', 'data' => [], 'limit' => 500, 'offset' => 0, 'total' => 0])]);

    $screen = Native::visit('/home/manga/'.DETAIL_SEEDED);
    // Opened on en (es-la has no readable chapters yet).
    expect($screen->get('language'))->toBe('en');

    $screen->call('pickLanguage', 'es-la');

    expect($screen->get('language'))->toBe('es-la')
        ->and(Http::recorded(fn (Request $r) => str_contains(rawurldecode($r->url()), 'translatedLanguage[]=es-la'))->count())->toBeGreaterThanOrEqual(1)
        ->and(detailRows($screen->tree()))->toHaveCount(0)
        ->and(detailTexts($screen->tree()))->toContain('No chapters in Español (Latinoamérica) yet');
});

it('has no language switch for a series in one language, and says when it has no chapters', function () {
    detailSeries(DETAIL_SEEDED, ['en']);
    Http::swap(new Factory(app('events')));
    Http::fake(['*' => Http::response(['result' => 'ok', 'response' => 'collection', 'data' => [], 'limit' => 500, 'offset' => 0, 'total' => 0])]);

    $screen = Native::visit('/home/manga/'.DETAIL_SEEDED);

    expect(json_encode($screen->tree()))->not->toContain('pickLanguage')
        ->and(detailTexts($screen->tree()))->toContain('No chapters yet');
});

it('shows pulsing chapter rows while the first chapter list waits for the source', function () {
    detailSeries(DETAIL_SEEDED, ['en']);
    ChapterFeed::query()->where('manga_id', DETAIL_SEEDED)->delete();
    $busy = true;
    Http::swap(new Factory(app('events')));
    Http::fake(function (Request $request) use (&$busy) {
        if ($busy && str_contains($request->url(), '/feed')) {
            throw new RequestDeferred(new Busy(Carbon::now()->addMinute()->getTimestampMs() / 1000, BusyReason::Saturated));
        }

        return Http::response(['result' => 'ok', 'response' => 'collection', 'data' => [], 'limit' => 500, 'offset' => 0, 'total' => 0]);
    });

    $waiting = Native::visit('/home/manga/'.DETAIL_SEEDED)->assertSee('The provider is busy');
    $busy = false;
    $answered = Native::visit('/home/manga/'.DETAIL_SEEDED)->assertSee('No chapters in English yet');

    expect(json_encode($waiting->tree()))->not->toContain('activity_indicator')
        ->and(count(Skeletons::pulsing($waiting->tree())) - count(Skeletons::pulsing($answered->tree())))->toBe(6 * 2);
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

it('opens a licensed chapter in the in-app browser and a readable one in the reader', function () {
    detailSeries(DETAIL_SEEDED, ['es-la']);
    seededChapter('ext-1', DETAIL_SEEDED, '1', ['group_name' => 'MangaPlus', 'external_url' => 'https://mangaplus.shueisha.co.jp/viewer/1000']);
    seededChapter('own-2', DETAIL_SEEDED, '2');

    $screen = Native::visit('/home/manga/'.DETAIL_SEEDED);
    expect(detailRows($screen->tree())[0]['caption'])->toBe('MangaPlus · Opens on mangaplus.shueisha.co.jp');

    $screen->tap('Ch. 1')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $p) => $p['url'] === 'https://mangaplus.shueisha.co.jp/viewer/1000')
        ->assertNativeNotCalled('Browser.Open');
    $screen->tap('Ch. 2')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Read'])
        ->assertNavigatedTo('/home/read/own-2')
        ->assertNativeNotCalled('Dialog.Toast')
        ->assertNativeCalledTimes('Browser.OpenInApp', 1);
});

it('opens chapters and Continue in the tab the series was opened from, a licensed one in the in-app browser', function () {
    detailSeries(DETAIL_SEEDED, ['es-la']);
    seededChapter('own-2', DETAIL_SEEDED, '2');
    seededChapter('ext-3', DETAIL_SEEDED, '3', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/3']);

    Native::visit('/search/manga/'.DETAIL_SEEDED)->tap('Ch. 2')->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Read'])->assertNavigatedTo('/search/read/own-2');
    Native::visit('/library/manga/'.DETAIL_SEEDED)->tap('Ch. 2')->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Read'])->assertNavigatedTo('/library/read/own-2');

    app(LibraryRepository::class)->saveProgress('own-2', 4, 20);
    Native::visit('/search/manga/'.DETAIL_SEEDED)->tap('Continue · Ch. 2, page 5')->assertNavigatedTo('/search/read/own-2');

    app(LibraryRepository::class)->saveProgress('own-2', 19, 20);
    Native::visit('/home/manga/'.DETAIL_SEEDED)->tap('Continue · Ch. 3')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $p) => $p['url'] === 'https://mangaplus.shueisha.co.jp/viewer/3')
        ->assertNoNavigation();
});

it('does nothing on Continue for a series never read, or on a chapter that is gone', function () {
    detailSeries(DETAIL_SEEDED, ['es-la']);

    Native::visit('/library/manga/'.DETAIL_SEEDED)->call('resumeReading')->call('readChapter', 'gone')
        ->assertNoNavigation()
        ->assertNativeNotCalled('Dialog.Toast')
        ->assertNativeNotCalled('Browser.OpenInApp');
});

/** Matches the settle pause after opening the chapter a tile sent (set to 357 ms, a length no other sleep has). */
function detailSettle(): Closure
{
    config(['readex.reader.via_detail_settle_ms' => 357]);

    return fn (CarbonInterval $d) => (int) $d->totalMilliseconds === 357;
}

it('opens the chapter a tile sent on top of itself, in its tab, after the settle pause, and not again when the reader closes', function () {
    detailSeries(DETAIL_SEEDED, ['es-la']);
    seededChapter('own-2', DETAIL_SEEDED, '2');

    Native::visit('/library/manga/'.DETAIL_SEEDED, ['read' => 'own-2'])->assertNavigatedTo('/library/read/own-2');
    Sleep::assertSlept(fn (CarbonInterval $d) => (int) $d->totalMilliseconds === 350, 1);

    $settle = detailSettle();
    Native::visit('/search/manga/'.DETAIL_SEEDED, ['read' => 'own-2'])->assertNavigatedTo('/search/read/own-2')
        ->follow()->assertScreen(Reader::class)
        ->goBack()->assertScreen(MangaDetail::class)->assertNoNavigation()->assertSee('Seeded');
    Sleep::assertSlept($settle, 1);
});

it('opens a licensed chapter a tile sent in the in-app browser and stays, without the pause', function () {
    detailSeries(DETAIL_SEEDED, ['es-la']);
    seededChapter('ext-3', DETAIL_SEEDED, '3', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/3']);
    $settle = detailSettle();

    Native::visit('/home/manga/'.DETAIL_SEEDED, ['read' => 'ext-3'])
        ->assertNativeCalled('Browser.OpenInApp', fn (array $p) => $p['url'] === 'https://mangaplus.shueisha.co.jp/viewer/3')
        ->assertNoNavigation()
        ->assertSee('Seeded');
    Sleep::assertSlept($settle, 0);
});

it('opens normally without a chapter, with one that is gone or with one of another series', function (array $data) {
    detailSeries(DETAIL_SEEDED, ['es-la']);
    seededChapter('own-2', DETAIL_SEEDED, '2');
    detailSeries(DETAIL_NOVA, ['es-la']);
    seededChapter('nova-1', DETAIL_NOVA, '1');
    seededChapter('nova-ext', DETAIL_NOVA, '2', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/9']);
    $settle = detailSettle();

    Native::visit('/library/manga/'.DETAIL_SEEDED, $data)
        ->assertNoNavigation()
        ->assertNativeNotCalled('Browser.OpenInApp')
        ->assertSee('Seeded');
    Sleep::assertSlept($settle, 0);
})->with([
    'no data' => [[]],
    'gone' => [['read' => 'gone']],
    'another series' => [['read' => 'nova-1']],
    'another series, licensed' => [['read' => 'nova-ext']],
    'another key' => [['chapter' => 'own-2']],
]);

/** Titles of the cover tiles (w-28 pressables) in screen order. */
function detailTileTitles(array $node): array
{
    if (($node['type'] ?? null) === 'pressable' && ($node['layout']['width'] ?? null) == 112) {
        return [$node['children'][0]['children'][1]['props']['text']];
    }

    $titles = [];

    foreach ($node['children'] ?? [] as $child) {
        $titles = [...$titles, ...detailTileTitles($child)];
    }

    return $titles;
}

it('shows More like this between the description and the chapters, in reading order, and opens a tile in the same tab', function () {
    $screen = Native::visit('/search/manga/'.DETAIL_RTW);
    $texts = detailTexts($screen->tree());
    $chain = app(SettingsRepository::class)->chain();
    $expected = app(RecommendationRepository::class)->get(DETAIL_RTW)->data;
    $description = collect($texts)->search(fn (string $t) => str_starts_with($t, 'A male engineer transmigrated'));

    expect(collect($this->asked)->filter(fn (string $url) => str_contains($url, '/manga/'.DETAIL_RTW.'/recommendation'))->all())->toHaveCount(1)
        ->and($expected)->toHaveCount(39)
        ->and(detailTileTitles($screen->tree()))->toBe($expected->map(fn (Manga $m) => $chain->title($m->title ?? [], $m->alt_titles ?? []))->all())
        ->and(array_search('More like this', $texts, true))->toBeGreaterThan($description)
        ->and(array_search('More like this', $texts, true))->toBeLessThan(array_search('Chapters', $texts, true));

    $first = $expected->first();
    $screen->tap($chain->title($first->title ?? [], $first->alt_titles ?? []))
        ->assertNavigatedTo("/search/manga/{$first->id}");
});

it('fetches the series cover and the first recommended covers right away, and queues the rest', function () {
    Native::visit('/search/manga/'.DETAIL_RTW);
    $fetched = Covers::fetched($this->async);
    $requested = Covers::requested($this->async);
    $expected = app(RecommendationRepository::class)->get(DETAIL_RTW)->data->pluck('id')->all();

    expect($fetched[0])->toBe(DETAIL_RTW)
        ->and($fetched)->toContain($expected[0])
        ->and(array_values(array_intersect(array_unique($requested), $expected)))->toBe($expected);
});

it('leaves the row out for a series nobody recommended anything for, and asks again only after 7 days', function () {
    $asked = fn () => collect($this->asked)->filter(fn (string $url) => str_contains($url, '/manga/'.DETAIL_NOVA.'/recommendation'))->count();

    expect(detailTexts(Native::visit('/home/manga/'.DETAIL_NOVA)->tree()))->not->toContain('More like this');
    Carbon::setTestNow(Carbon::now()->addDay());
    Native::visit('/home/manga/'.DETAIL_NOVA);
    expect($asked())->toBe(1);

    Carbon::setTestNow(Carbon::now()->addDays(6));
    Native::visit('/home/manga/'.DETAIL_NOVA);
    expect($asked())->toBe(2);
});

it('keeps the row in place with placeholders while The provider is busy, and leaves it out when the call failed', function () {
    $fixtures = SourceFixtures::stub('manga-rtw', 'statistics-rtw', 'feed-rtw-es-la-p1', 'feed-rtw-es-la-p2');
    $fail = new RequestDeferred(new Busy(Carbon::now()->addMinute()->getTimestampMs() / 1000, BusyReason::Saturated));
    Http::swap(new Factory(app('events')));
    Http::fake(function (Request $request) use ($fixtures, &$fail) {
        return str_contains($request->url(), '/recommendation') ? throw $fail : $fixtures($request);
    });

    $busy = Native::visit('/search/manga/'.DETAIL_RTW);
    expect(detailTexts($busy->tree()))->toContain('More like this')
        ->and(detailTileTitles($busy->tree()))->toBe([])
        ->and(Skeletons::tiles($busy->tree()))->toBe(4);

    $fail = new ConnectionException('offline');
    $offline = Native::visit('/search/manga/'.DETAIL_RTW);
    expect(detailTexts($offline->tree()))->not->toContain('More like this')
        ->and(Skeletons::tiles($offline->tree()))->toBe(0);
});
