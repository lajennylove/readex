<?php

use App\Jobs\DownloadCovers;
use App\Models\Chapter;
use App\Models\Manga;
use App\Models\ReadingProgress;
use App\NativeComponents\Home;
use App\NativeComponents\Library;
use App\NativeComponents\MangaDetail;
use App\NativeComponents\Search;
use App\NativeComponents\Settings;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Support\Covers;

const RB_SOLO = 'rb000000-0000-4000-8000-000000000001';
const RB_OTHER = 'rb000000-0000-4000-8000-000000000002';
const RB_CH12 = 'rbc00000-0000-4000-8000-000000000012';
const RB_CH13 = 'rbc00000-0000-4000-8000-000000000013';
const RB_ONESHOT = 'rbc00000-0000-4000-8000-000000000099';
const RB_OTHER_CH1 = 'rbc00000-0000-4000-8000-000000000201';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    Http::preventStrayRequests();
    $this->caches = Covers::useTempCaches();
    $this->async = AsyncTask::fake();
    Queue::fake();
    app(SettingsRepository::class)->setLanguage('es-la');
    $this->library = app(LibraryRepository::class);

    // The reading chain (es-la) picks the alt title, not the English one.
    $this->solo = Covers::manga(RB_SOLO, title: 'Solo Leveling');
    $this->solo->update(['alt_titles' => [['es-la' => 'Solo Leveling ES']]]);
    Covers::manga(RB_OTHER, title: 'Other Series');
    rbChapter(RB_CH12, RB_SOLO, '12', 43);
    rbChapter(RB_CH13, RB_SOLO, '13', 40);
    rbChapter(RB_OTHER_CH1, RB_OTHER, '1', 20);
});

afterEach(fn () => AsyncTask::clearFake());

function rbChapter(string $id, string $mangaId, ?string $number, int $pages, array $extra = []): Chapter
{
    return Chapter::query()->create([
        'id' => $id, 'manga_id' => $mangaId, 'volume' => null, 'number' => $number, 'title' => null,
        'language' => 'es-la', 'group_id' => 'g-rb', 'group_name' => 'Group RB', 'pages' => $pages,
        'external_url' => null, 'readable_at' => Carbon::now(), 'fetched_at' => Carbon::now(), ...$extra,
    ]);
}

/** @return list<array> every node of this type under `$node` */
function rbNodes(array $node, string $type): array
{
    $found = ($node['type'] ?? null) === $type ? [$node] : [];

    foreach ($node['children'] ?? [] as $child) {
        $found = [...$found, ...rbNodes($child, $type)];
    }

    return $found;
}

/** The node TabsLayout pins above the tab bar, or null. */
function rbBar(TestableComponent $screen): ?array
{
    return rbNodes($screen->tree(), 'tab_accessory')[0] ?? null;
}

/** @return list<string> the bar's texts, in order */
function rbTexts(TestableComponent $screen): array
{
    return array_map(fn (array $n) => $n['props']['text'], rbNodes(rbBar($screen) ?? [], 'text'));
}

/** Id EDGE derives for a `native:key` path from the bar's root. */
function rbKeyed(string ...$keys): int
{
    return Element::fnv1a32('/'.implode('/', $keys));
}

/** As if the router had already moved on to `$uri` while the tap was on its way. */
function rbRouterAt(TestableComponent $screen, string $uri): void
{
    $router = (fn () => $this->nativeRouter)->call($screen->instance());
    (function () use ($uri) {
        $this->stack[] = ['component' => null, 'uri' => $uri, 'params' => []];
    })->call($router);
    $screen->instance()->resetNavigationIntent();
}

it('shows no bar when nothing has been read', function (string $uri) {
    expect(rbBar(Native::visit($uri)))->toBeNull();
})->with(['/home', '/search', '/library', '/downloads', '/settings', '/home/manga/'.RB_OTHER]);

it('shows the bar on every tab screen and on Detail, with the chain title and the saved page', function (string $uri) {
    $this->library->saveProgress(RB_CH12, 4, 43);

    $screen = Native::visit($uri);
    $bar = rbBar($screen);

    expect($bar)->not->toBeNull()
        ->and(rbTexts($screen))->toBe(['Solo Leveling ES', 'Ch. 12 · page 5 / 43'])
        ->and(rbNodes($bar, 'pressable')[0]['props']['a11y_label'])->toBe('Resume Solo Leveling ES, Ch. 12, page 5 of 43');
})->with(['/home', '/search', '/library', '/downloads', '/settings', '/home/manga/'.RB_SOLO, '/search/manga/'.RB_OTHER]);

it('says Up next for the next, unopened chapter, and only the chapter when the page count is unknown', function () {
    $this->library->saveProgress(RB_CH12, 42, 43);

    $screen = Native::visit('/home');
    expect(rbTexts($screen))->toBe(['Solo Leveling ES', 'Ch. 13 · Up next'])
        ->and(rbNodes(rbBar($screen), 'pressable')[0]['props']['a11y_label'])->toBe('Resume Solo Leveling ES, Ch. 13, up next');

    ReadingProgress::query()->create(['chapter_id' => RB_OTHER_CH1, 'manga_id' => RB_OTHER, 'page' => 0, 'page_count' => 0, 'updated_at' => Carbon::now()->addMinute()]);

    $screen = Native::visit('/home');
    expect(rbTexts($screen))->toBe(['Other Series', 'Ch. 1'])
        ->and(rbNodes(rbBar($screen), 'pressable')[0]['props']['a11y_label'])->toBe('Resume Other Series, Ch. 1');
});

it('calls a chapter without a number a oneshot', function () {
    rbChapter(RB_ONESHOT, RB_OTHER, null, 20);
    $this->library->saveProgress(RB_ONESHOT, 0, 20);

    expect(rbTexts(Native::visit('/library')))->toBe(['Other Series', 'Oneshot · page 1 / 20']);
});

it('shows a progress bar of the chapter while reading, none for Up next', function () {
    $this->library->saveProgress(RB_CH12, 9, 40);

    $bars = rbNodes(rbBar(Native::visit('/home')), 'progress_bar');
    expect($bars)->toHaveCount(1)->and($bars[0]['props']['value'])->toEqual(0.25);

    $this->library->saveProgress(RB_CH12, 39, 40);
    expect(rbNodes(rbBar(Native::visit('/home')), 'progress_bar'))->toBe([]);
});

it('shows the stored cover, else a keyed placeholder, and never asks for a cover itself', function () {
    $this->library->saveProgress(RB_CH12, 4, 43);

    $bar = rbBar(Native::visit('/settings'));
    $placeholders = array_values(array_filter(rbNodes($bar, 'stack'), fn (array $n) => $n['id'] === rbKeyed('resume-bar', 'resume-bar-row', 'resume-bar-placeholder')));

    expect(rbNodes($bar, 'image'))->toBe([])
        ->and($placeholders)->toHaveCount(1)
        ->and(rbNodes($placeholders[0], 'icon'))->toHaveCount(1)
        ->and(Covers::requested($this->async))->toBe([]);
    Queue::assertNotPushed(DownloadCovers::class);

    Covers::store($this->caches, $this->solo);
    $bar = rbBar(Native::visit('/settings'));
    $images = rbNodes($bar, 'image');

    expect($images)->toHaveCount(1)
        ->and($images[0]['props']['src'])->toBe('storage/cache/covers/'.RB_SOLO.'/cover.jpg.256.jpg')
        ->and($images[0]['id'])->toBe(rbKeyed('resume-bar', 'resume-bar-row', 'resume-bar-cover'))
        ->and(array_filter(rbNodes($bar, 'stack'), fn (array $n) => $n['id'] === rbKeyed('resume-bar', 'resume-bar-row', 'resume-bar-placeholder')))->toBe([]);
});

it('hides the bar on the Reader and the page viewer', function (string $uri) {
    $this->library->saveProgress(RB_CH12, 4, 43);

    expect(rbBar(Native::visit($uri)))->toBeNull();
})->with(['/home/read/'.RB_CH12, '/library/read/'.RB_CH12.'/page/1']);

it('hides the bar when the series read last is caught up, even with another series mid-read', function () {
    $this->library->saveProgress(RB_OTHER_CH1, 5, 20);
    Carbon::setTestNow('2026-10-24 13:00:00');
    $this->library->saveProgress(RB_CH12, 42, 43);
    Carbon::setTestNow('2026-10-24 14:00:00');
    $this->library->saveProgress(RB_CH13, 39, 40);

    expect(rbBar(Native::visit('/home')))->toBeNull();
});

it('shows the new page after reading, on the next render', function () {
    $this->library->saveProgress(RB_CH12, 4, 43);
    $home = Native::visit('/home');

    Carbon::setTestNow('2026-10-24 12:10:00');
    $this->library->saveProgress(RB_CH12, 20, 43);
    $home->call('onResume');

    expect(rbTexts($home))->toBe(['Solo Leveling ES', 'Ch. 12 · page 21 / 43']);
});

it('opens the series Detail in the tab, which opens the chapter, from a tab screen', function (string $uri, string $tab) {
    $this->library->saveProgress(RB_CH12, 4, 43);

    $screen = Native::visit($uri)->press('openResumeBar')->assertNavigatedTo("/{$tab}/manga/".RB_SOLO);

    expect($screen->navigationIntent()->data)->toBe([MangaDetail::READ => RB_CH12]);
    $screen->follow()->assertScreen(MangaDetail::class)->assertNavigatedTo("/{$tab}/read/".RB_CH12);
})->with([
    ['/home', 'home'],
    ['/search', 'search'],
    ['/library', 'library'],
    ['/settings', 'home'],
]);

it('opens another series via its Detail from a Detail screen', function () {
    $this->library->saveProgress(RB_CH12, 4, 43);

    $screen = Native::visit('/search/manga/'.RB_OTHER)->press('openResumeBar')->assertNavigatedTo('/search/manga/'.RB_SOLO);

    expect($screen->navigationIntent()->data)->toBe([MangaDetail::READ => RB_CH12]);
});

it('pushes the reader straight onto the series own Detail', function () {
    $this->library->saveProgress(RB_CH12, 4, 43);

    $screen = Native::visit('/library/manga/'.RB_SOLO)->press('openResumeBar')->assertNavigatedTo('/library/read/'.RB_CH12);

    expect($screen->navigationIntent()->data)->toBe([]);
});

it('opens a licensed chapter in the in-app browser from its series Detail, without a push', function () {
    Chapter::query()->whereKey(RB_CH12)->update(['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/12']);
    ReadingProgress::query()->create(['chapter_id' => RB_CH12, 'manga_id' => RB_SOLO, 'page' => 0, 'page_count' => 0, 'updated_at' => Carbon::now()]);

    Native::visit('/home/manga/'.RB_SOLO)->press('openResumeBar')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $p) => $p['url'] === 'https://mangaplus.shueisha.co.jp/viewer/12')
        ->assertNoNavigation();
});

it('pushes nothing again once the router is on that chapter, and no second Detail on a double tap', function () {
    $this->library->saveProgress(RB_CH12, 4, 43);

    $detail = Native::visit('/home/manga/'.RB_SOLO);
    rbRouterAt($detail, '/home/read/'.RB_CH12);
    $detail->press('openResumeBar')->assertNoNavigation()->assertNativeNotCalled('Browser.OpenInApp');

    $home = Native::visit('/home')->press('openResumeBar')->assertNavigatedTo('/home/manga/'.RB_SOLO);
    rbRouterAt($home, '/home/manga/'.RB_SOLO);
    $home->press('openResumeBar')->assertNavigatedTo('/home/read/'.RB_CH12);
    expect($home->navigationIntent()->data)->toBe([]);
});

it('reads the last chapter again at tap time: another one opens it, none does nothing', function () {
    $this->library->saveProgress(RB_CH12, 4, 43);
    $home = Native::visit('/home');

    Carbon::setTestNow('2026-10-24 12:10:00');
    $this->library->saveProgress(RB_OTHER_CH1, 2, 20);
    $home->press('openResumeBar')->assertNavigatedTo('/home/manga/'.RB_OTHER);
    expect($home->navigationIntent()->data)->toBe([MangaDetail::READ => RB_OTHER_CH1]);

    $home->instance()->resetNavigationIntent();
    ReadingProgress::query()->delete();
    $home->press('openResumeBar')->assertNoNavigation();
});

it('keeps a nav title on every screen that shows the bar', function () {
    $this->library->saveProgress(RB_CH12, 4, 43);

    foreach ([[Home::class, '/home'], [Search::class, '/search'], [Library::class, '/library'], [Settings::class, '/settings'], [MangaDetail::class, '/home/manga/'.RB_SOLO]] as [$class, $uri]) {
        $screen = Native::visit($uri)->assertScreen($class);
        expect($screen->instance()->navTitle())->not->toBe('')
            ->and(rbBar($screen))->not->toBeNull();
    }
});

it('renders the bar without dropped Tailwind classes', function () {
    config(['app.debug' => true]);
    (fn () => self::$reportedUnsupportedByView = [])->bindTo(null, TailwindParser::class)();
    TailwindParser::clearCache();
    $dropped = [];
    Log::listen(function (MessageLogged $log) use (&$dropped) {
        if (str_contains($log->message, 'dropped unsupported')) {
            $dropped[] = $log->context;
        }
    });

    $this->library->saveProgress(RB_CH12, 4, 43);
    Native::visit('/settings');
    Covers::store($this->caches, Manga::query()->find(RB_SOLO));
    Native::visit('/home');

    expect($dropped)->toBe([]);
});
