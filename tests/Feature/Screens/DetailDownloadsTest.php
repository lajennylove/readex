<?php

use App\Adapters\Http\RateLimit\Priority;
use App\Downloads\Downloads;
use App\Jobs\DownloadChapter;
use App\Models\Chapter;
use App\Models\ChapterFeed;
use App\Models\Download;
use App\Models\Manga;
use App\Models\ReadingProgress;
use App\Models\Statistic;
use App\Models\StoredChapter;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\Stored;

const T7_DETAIL = '00000000-0000-4000-8000-0000000007d7';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    $this->caches = Covers::useTempCaches();
    $this->storage = Stored::useTempStorage();
    app(SettingsRepository::class)->setLanguage('es-la');
    AsyncTask::fake();
    Queue::fake();
    Http::fake(fn () => throw new RuntimeException('no network on a seeded Detail'));
    t7dSeries(['es-la', 'en']);
});

afterEach(fn () => AsyncTask::clearFake());

function t7dSeries(array $languages): void
{
    Manga::query()->create([
        'id' => T7_DETAIL, 'title' => ['en' => 'Downloads'], 'alt_titles' => [], 'description' => [], 'status' => 'ongoing', 'year' => 2020,
        'content_rating' => 'safe', 'available_languages' => $languages, 'original_language' => 'ja', 'tag_ids' => [],
        'cover_file' => null, 'authors' => ['A'], 'artists' => ['A'], 'fetched_at' => Carbon::now(),
        'recommendations_fetched_at' => Carbon::now(),
    ]);
    Statistic::query()->create(['manga_id' => T7_DETAIL, 'rating_avg' => null, 'rating_bayesian' => null, 'follows' => 0, 'distribution' => [], 'fetched_at' => Carbon::now()]);

    foreach ($languages as $language) {
        ChapterFeed::query()->updateOrInsert(['manga_id' => T7_DETAIL, 'language' => $language], ['total' => 0, 'fetched_at' => Carbon::now()]);
    }
}

function t7dChapter(string $id, ?string $number, array $extra = []): Chapter
{
    return Chapter::query()->create([
        'id' => $id, 'manga_id' => T7_DETAIL, 'volume' => null, 'number' => $number, 'title' => null,
        'language' => 'es-la', 'group_id' => 't7-dg', 'group_name' => 'Group A', 'pages' => 20,
        'external_url' => null, 'readable_at' => Carbon::now(), 'fetched_at' => Carbon::now(), ...$extra,
    ]);
}

function t7dEntry(string $id, int $position, ?string $started = null, ?string $finished = null, ?string $batch = 't7-batch', ?string $state = null): void
{
    // chore/0025: downloads.state is the single source of truth. Historically
    // tests set only started_at/finished_at and let the caption derive from
    // those; now the row's state must match. Default state follows the
    // timestamps: finished_at set → done, started_at set → downloading, else queued.
    $state ??= $finished !== null ? 'done' : ($started !== null ? 'downloading' : 'queued');
    Download::query()->create([
        'chapter_id' => $id, 'manga_id' => T7_DETAIL, 'batch' => $batch, 'position' => $position, 'priority' => 'bulk',
        'started_at' => $started, 'finished_at' => $finished, 'created_at' => Carbon::now(), 'state' => $state,
    ]);
}

/** @return list<array{label: string, caption: string, a11y: string, icon: ?string, dimmed: bool}> */
function t7dRows(array $node): array
{
    if (($node['type'] ?? null) === 'pressable' && isset($node['props']['a11y_label']) && ($node['children'][0]['type'] ?? null) === 'column') {
        [$label, $caption] = $node['children'][0]['children'];
        $icon = null;

        if ($caption['type'] === 'row') {
            $icon = collect($caption['children'])->firstWhere('type', 'icon')['props']['name'] ?? null;
            $caption = collect($caption['children'])->firstWhere('type', 'text');
        }

        return [[
            'label' => $label['props']['text'],
            'caption' => $caption['props']['text'],
            'a11y' => $node['props']['a11y_label'],
            'icon' => $icon,
            'dimmed' => $label['props']['color'] === $caption['props']['color'],
        ]];
    }

    return array_merge([], ...array_map(t7dRows(...), $node['children'] ?? []));
}

/** @return list<array{label: string, icon: ?string}> */
function t7dButtons(array $node): array
{
    $buttons = ($node['type'] ?? null) === 'button' ? [['label' => $node['props']['label'] ?? '', 'icon' => $node['props']['leading_icon'] ?? null]] : [];

    return array_merge($buttons, ...array_map(t7dButtons(...), $node['children'] ?? []));
}

function t7dLabels(array $node): array
{
    return array_column(t7dButtons($node), 'label');
}

/** Matches a Dialog.Alert with this title, message and buttons (Cancel first). */
function t7dAlert(string $title, string $message, array $buttons): Closure
{
    $expected = [['label' => 'Cancel', 'style' => 'cancel'], ...array_map(fn ($b) => is_array($b) ? $b : ['label' => $b, 'style' => 'default'], $buttons)];

    return fn (array $p) => $p['title'] === $title && $p['message'] === $message && $p['buttons'] === $expected;
}

function t7dPress(string $label): array
{
    return ['index' => 1, 'label' => $label];
}

const T7_REMOVE_MESSAGE = 'It stays readable until the image cache needs the space. Your progress stays.';

describe('chapter rows', function () {
    it('shows the download state instead of the reading status, a checkmark on a downloaded chapter, and keeps read chapters dimmed', function () {
        t7dChapter('t7-d1', '1');
        t7dChapter('t7-d2', '2');
        t7dChapter('t7-d3', '3');
        t7dChapter('t7-d4', '4');
        t7dChapter('t7-d5', '5', ['title' => 'Last']);
        t7dChapter('t7-d6', '6');
        app(LibraryRepository::class)->saveProgress('t7-d1', 4, 20);
        Stored::partial('t7-d2', 9, 20, tier: 'local');
        app(Downloads::class)->download('t7-d2');
        t7dEntry('t7-d3', 1);
        Stored::partial('t7-d4', 9, 20, tier: 'local');
        Carbon::setTestNow(Carbon::now()->addMinute());
        app(LibraryRepository::class)->saveProgress('t7-d4', 3, 20);
        Stored::chapter('t7-d5', 2, tier: 'local');
        Carbon::setTestNow(Carbon::now()->addMinute());
        app(LibraryRepository::class)->saveProgress('t7-d5', 1, 2);
        Stored::chapter('t7-d6', 2);

        expect(t7dRows(Native::visit('/library/manga/'.T7_DETAIL)->tree()))->toBe([
            ['label' => 'Ch. 1', 'caption' => 'Group A · Page 5 of 20', 'a11y' => 'Ch. 1', 'icon' => null, 'dimmed' => false],
            ['label' => 'Ch. 2', 'caption' => 'Group A · Queued', 'a11y' => 'Ch. 2, Queued', 'icon' => null, 'dimmed' => false],
            ['label' => 'Ch. 3', 'caption' => 'Group A · Queued', 'a11y' => 'Ch. 3, Queued', 'icon' => null, 'dimmed' => false],
            ['label' => 'Ch. 4', 'caption' => 'Group A · Download stopped · 45%', 'a11y' => 'Ch. 4, Download stopped · 45%', 'icon' => null, 'dimmed' => false],
            ['label' => 'Ch. 5 · Last', 'caption' => 'Group A · Downloaded', 'a11y' => 'Ch. 5 · Last, Downloaded', 'icon' => 'checkmark.circle.fill', 'dimmed' => true],
            ['label' => 'Ch. 6', 'caption' => 'Group A · Up next', 'a11y' => 'Ch. 6', 'icon' => null, 'dimmed' => false],
        ]);
    });

    it('says Queued until a page is in the downloads, even once its job is dispatched', function () {
        t7dChapter('t7-d1', '1', ['pages' => 20]);
        t7dChapter('t7-d2', '2', ['pages' => 0]);
        t7dChapter('t7-d3', '3');
        t7dEntry('t7-d1', 0, started: '2026-10-24 11:00:00');
        t7dEntry('t7-d2', 0, started: '2026-10-24 11:00:00', batch: null);
        t7dEntry('t7-d3', 0, started: '2026-10-24 11:00:00', batch: null);
        Stored::partial('t7-d3', 1, 20, tier: 'local');

        expect(array_column(t7dRows(Native::visit('/home/manga/'.T7_DETAIL)->tree()), 'caption'))
            ->toBe(['Group A · Queued', 'Group A · Queued', 'Group A · Downloading 5%']);
    });

    it('says Queued for a stopped download waiting in a Download all, and Downloading once its turn starts', function () {
        t7dChapter('t7-d1', '1');
        t7dChapter('t7-d2', '2');
        t7dEntry('t7-d1', 0, started: '2026-10-24 11:00:00');
        Stored::partial('t7-d2', 9, 20, tier: 'local');
        t7dEntry('t7-d2', 1);

        $screen = Native::visit('/home/manga/'.T7_DETAIL);
        expect(array_column(t7dRows($screen->tree()), 'caption'))->toBe(['Group A · Queued', 'Group A · Queued']);
        $screen->tap('Ch. 2')->assertNativeCalled('Dialog.Alert', t7dAlert('Ch. 2', '', ['Read', 'Stop download']));

        app(Downloads::class)->stop('t7-d1');

        Queue::assertPushed(DownloadChapter::class, fn (DownloadChapter $j) => $j->chapterId === 't7-d2' && $j->tier === 'local');
        expect(array_column(t7dRows(Native::visit('/home/manga/'.T7_DETAIL)->tree()), 'caption'))->toBe(['Group A', 'Group A · Queued']);
    });

    it('looks up the download state of every row in one query, however long the list', function () {
        $queries = function (int $chapters): int {
            DB::table('chapters')->delete();
            foreach (range(1, $chapters) as $n) {
                t7dChapter("t7-dq{$chapters}-{$n}", (string) $n);
                Stored::chapter("t7-dq{$chapters}-{$n}", 1, tier: 'local');
            }
            $screen = Native::visit('/home/manga/'.T7_DETAIL);
            $count = 0;
            DB::listen(function () use (&$count) {
                $count++;
            });
            $screen->call('checkWaitingRefreshes');

            return $count;
        };

        expect($queries(2))->toBe($queries(12));
    });
});

describe('row choices', function () {
    it('asks Read or Download for a chapter not downloaded; Read opens the reader on top of the series', function () {
        t7dChapter('t7-d1', '1', ['title' => 'Start']);
        Stored::chapter('t7-d1', 2);

        Native::visit('/search/manga/'.T7_DETAIL)->tap('Ch. 1 · Start')
            ->assertNativeCalled('Dialog.Alert', t7dAlert('Ch. 1 · Start', '', ['Read', 'Download']))
            ->assertNoNavigation()
            ->emitNative(ButtonPressed::class, t7dPress('Read'))
            ->assertNavigatedTo('/search/read/t7-d1');

        Queue::assertNotPushed(DownloadChapter::class);
    });

    it('downloads from the row and stays on the series', function () {
        t7dChapter('t7-d1', '1');

        $screen = Native::visit('/home/manga/'.T7_DETAIL)->tap('Ch. 1')->emitNative(ButtonPressed::class, t7dPress('Download'))
            ->assertNoNavigation();

        Queue::assertPushed(DownloadChapter::class, fn (DownloadChapter $j) => $j->chapterId === 't7-d1' && $j->tier === 'local' && $j->priority === Priority::Prefetch);
        expect(t7dRows($screen->tree())[0]['caption'])->toBe('Group A · Queued');
    });

    it('asks Read or Stop download while queued or downloading; Stop takes it out of the queue', function (int $stored, string $caption, string $stopped) {
        t7dChapter('t7-d1', '1');
        t7dEntry('t7-d1', 0, started: '2026-10-24 11:00:00', batch: null);
        if ($stored > 0) {
            Stored::partial('t7-d1', $stored, 20, tier: 'local');
        }

        $screen = Native::visit('/home/manga/'.T7_DETAIL);
        expect(t7dRows($screen->tree())[0]['caption'])->toBe($caption);

        $screen->tap('Ch. 1')
            ->assertNativeCalled('Dialog.Alert', t7dAlert('Ch. 1', '', ['Read', 'Stop download']))
            ->emitNative(ButtonPressed::class, t7dPress('Stop download'))
            ->assertNoNavigation();

        expect(Download::query()->count())->toBe(0)
            ->and(t7dRows($screen->tree())[0]['caption'])->toBe($stopped);
    })->with([
        'queued' => [0, 'Group A · Queued', 'Group A'],
        'downloading' => [9, 'Group A · Downloading 45%', 'Group A · Download stopped · 45%'],
    ]);

    it('asks Read, Download or Remove download for a stopped download', function () {
        t7dChapter('t7-d1', '1');
        Stored::partial('t7-d1', 9, 20, tier: 'local');

        Native::visit('/home/manga/'.T7_DETAIL)->tap('Ch. 1')
            ->assertNativeCalled('Dialog.Alert', t7dAlert('Ch. 1', T7_REMOVE_MESSAGE, ['Read', 'Download', ['label' => 'Remove download', 'style' => 'destructive']]))
            ->emitNative(ButtonPressed::class, t7dPress('Download'));

        Queue::assertPushed(DownloadChapter::class, fn (DownloadChapter $j) => $j->chapterId === 't7-d1' && $j->tier === 'local');
    });

    it('asks Read or Remove download for a downloaded chapter; Remove moves it back to the cache, the pages and progress stay', function () {
        t7dChapter('t7-d1', '1');
        Stored::chapter('t7-d1', 2, tier: 'local');
        app(LibraryRepository::class)->saveProgress('t7-d1', 1, 2);

        $screen = Native::visit('/home/manga/'.T7_DETAIL)->tap('Ch. 1')
            ->assertNativeCalled('Dialog.Alert', t7dAlert('Ch. 1', T7_REMOVE_MESSAGE, ['Read', ['label' => 'Remove download', 'style' => 'destructive']]))
            ->emitNative(ButtonPressed::class, t7dPress('Remove download'))
            ->assertNoNavigation()
            ->assertNativeCalledTimes('Dialog.Alert', 1);

        expect(StoredChapter::query()->find('t7-d1')->tier)->toBe('cache')
            ->and(is_file("{$this->caches}/chapters/t7-d1/002.jpg"))->toBeTrue()
            ->and(ReadingProgress::query()->find('t7-d1')->page)->toBe(1)
            ->and(t7dRows($screen->tree())[0]['caption'])->toBe('Group A · Read');
    });

    it('does nothing on Cancel', function () {
        t7dChapter('t7-d1', '1');
        Stored::chapter('t7-d1', 2, tier: 'local');

        Native::visit('/home/manga/'.T7_DETAIL)->tap('Ch. 1')->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel'])
            ->assertNoNavigation();

        expect(StoredChapter::query()->find('t7-d1')->tier)->toBe('local');
        Queue::assertNotPushed(DownloadChapter::class);
    });

    it('opens a licensed chapter in the in-app browser without asking, and Continue opens the reader without asking', function () {
        t7dChapter('t7-d1', '1', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/71']);
        t7dChapter('t7-d2', '2');
        app(LibraryRepository::class)->saveProgress('t7-d2', 4, 20);

        Native::visit('/home/manga/'.T7_DETAIL)->tap('Ch. 1')
            ->assertNativeCalled('Browser.OpenInApp', fn (array $p) => $p['url'] === 'https://mangaplus.shueisha.co.jp/viewer/71')
            ->assertNativeNotCalled('Dialog.Alert');

        Native::visit('/home/manga/'.T7_DETAIL)->tap('Continue · Ch. 2, page 5')
            ->assertNavigatedTo('/home/read/t7-d2')
            ->assertNativeNotCalled('Dialog.Alert');
    });
});

describe('Download all', function () {
    it('sits next to Favorite and asks with the number of chapters and the language', function () {
        t7dChapter('t7-d1', '1');
        t7dChapter('t7-d2', '2');
        t7dChapter('t7-d2b', '2', ['group_id' => 't7-dgb', 'group_name' => 'Group B']);
        t7dChapter('t7-d3', '3', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/73']);
        t7dChapter('t7-d4', '4');
        Stored::chapter('t7-d4', 2, tier: 'local');
        t7dChapter('t7-den', '1', ['language' => 'en']);

        $screen = Native::visit('/home/manga/'.T7_DETAIL, platform: 'ios');

        expect(t7dButtons($screen->tree()))->toBe([
            ['label' => 'Favorite', 'icon' => 'heart'],
            ['label' => 'Download all', 'icon' => 'arrow.down.circle'],
        ]);

        $screen->tap('Download all')
            ->assertNativeCalled('Dialog.Alert', t7dAlert('Download all', 'Download 2 chapters in Español (Latinoamérica)?', ['Download']))
            ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel']);
        Queue::assertNotPushed(DownloadChapter::class);

        $screen->toggle("pickLanguage('en')", true)->tap('Download all')
            ->assertNativeCalled('Dialog.Alert', t7dAlert('Download all', 'Download 1 chapter in English?', ['Download']));
    });

    it('queues the batch on Download and dispatches one chapter at bulk priority', function () {
        foreach (range(1, 3) as $n) {
            t7dChapter("t7-d{$n}", (string) $n);
        }

        $screen = Native::visit('/home/manga/'.T7_DETAIL)->tap('Download all')
            ->emitNative(ButtonPressed::class, t7dPress('Download'));

        Queue::assertPushed(DownloadChapter::class, 1);
        Queue::assertPushed(DownloadChapter::class, fn (DownloadChapter $j) => $j->chapterId === 't7-d1' && $j->tier === 'local' && $j->priority === Priority::Bulk);
        expect(t7dLabels($screen->tree()))->toContain('Downloading 0 / 3 · 0%')
            ->and(array_column(t7dRows($screen->tree()), 'caption'))->toBe(['Group A · Queued', 'Group A · Queued', 'Group A · Queued'])
            ->and(app(LibraryRepository::class)->preferredGroup(T7_DETAIL))->toBe('t7-dg');
    });

    it('shows the batch progress and stops it at once on tap, keeping what is downloaded', function () {
        foreach (range(1, 3) as $n) {
            t7dChapter("t7-d{$n}", (string) $n);
        }
        Stored::chapter('t7-d1', 20, tier: 'local');
        Stored::partial('t7-d2', 5, 20, tier: 'local');
        t7dEntry('t7-d1', 0, started: '2026-10-24 11:00:00', finished: '2026-10-24 11:05:00');
        t7dEntry('t7-d2', 1, started: '2026-10-24 11:05:00');
        t7dEntry('t7-d3', 2);

        $screen = Native::visit('/home/manga/'.T7_DETAIL, platform: 'ios');
        expect(t7dButtons($screen->tree())[1])->toBe(['label' => 'Downloading 1 / 3 · 41%', 'icon' => 'stop.circle']);

        $screen->tap('Downloading 1 / 3 · 41%')->assertNativeNotCalled('Dialog.Alert');

        expect(t7dLabels($screen->tree()))->toContain('Download all')
            ->and(array_column(t7dRows($screen->tree()), 'caption'))->toBe(['Group A · Downloaded', 'Group A · Download stopped · 25%', 'Group A']);
    });

    it('says Downloaded once every readable chapter of the language is downloaded, and does nothing on tap', function () {
        t7dChapter('t7-d1', '1');
        t7dChapter('t7-d2', '2', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/72']);
        Stored::chapter('t7-d1', 2, tier: 'local');

        $screen = Native::visit('/home/manga/'.T7_DETAIL, platform: 'ios');
        expect(t7dButtons($screen->tree())[1])->toBe(['label' => 'Downloaded', 'icon' => 'checkmark.circle']);

        $screen->tap('Downloaded')->assertNativeNotCalled('Dialog.Alert');
        Queue::assertNotPushed(DownloadChapter::class);
    });

    it('is not there when the language has no readable chapter', function () {
        t7dChapter('t7-d1', '1', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/71']);
        t7dChapter('t7-den', '1', ['language' => 'en']);

        $screen = Native::visit('/home/manga/'.T7_DETAIL);
        expect(t7dLabels($screen->tree()))->toBe(['Favorite']);

        $screen->toggle("pickLanguage('en')", true);
        expect(t7dLabels($screen->tree()))->toBe(['Favorite', 'Download all']);
    });
});
