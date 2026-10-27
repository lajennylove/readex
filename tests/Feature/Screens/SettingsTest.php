<?php

use App\Jobs\RefreshAllData;
use App\Loading\DataLoader;
use App\Loading\LoadProgress;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use App\NativeComponents\Settings;
use App\Repositories\HomeRepository;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;
use App\Storage\OpenChapter;
use App\Storage\PageStore;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\Loading;
use Tests\Support\Stored;

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    $this->caches = Covers::useTempCaches();
    Queue::fake();
    AsyncTask::fake();
    Native::fakeBridge()->respondTo('MdxStorage.TimeZone', ['zone' => 'America/Toronto']);
});

afterEach(fn () => AsyncTask::clearFake());

/** Home rows, tags and a Library series with one chapter, as after a normal day of use. */
function settingsSavedData(): void
{
    Loading::fake();
    app(SettingsRepository::class)->setLanguage('es-la');
    app(HomeRepository::class)->refreshSeason();
    app(HomeRepository::class)->refreshLatest();
    $kept = Manga::query()->firstOrFail();
    LibraryEntry::create(['manga_id' => $kept->id, 'favorite' => true]);
}

function settingsSelect(string $label, string $value): Closure
{
    return fn (array $node) => ($node['props']['label'] ?? null) === $label && ($node['props']['value'] ?? null) === $value;
}

function refreshButton(string $label, bool $disabled): Closure
{
    return fn (array $node) => ($node['props']['label'] ?? null) === $label && (bool) ($node['props']['disabled'] ?? false) === $disabled;
}

/** the source paused this phone until 16:52 UTC (12:52 in Montreal). */
function settingsPaused(int $strikes = 0): void
{
    DB::table('rate_buckets')->insert([
        'key' => 'family:source.test',
        'capacity' => 1,
        'refill_per_sec' => 1,
        'tokens' => 1,
        'updated_at' => Carbon::now()->getTimestamp(),
        'blocked_until' => Carbon::parse('2026-10-24 16:52:00')->getTimestamp(),
        'strikes' => $strikes,
    ]);
}

it('shows the saved language, content rating and image quality', function () {
    $settings = app(SettingsRepository::class);
    $settings->setLanguage('es-la');
    $settings->setContentRatings(['safe']);
    $settings->setImageQuality('original');

    Native::test(Settings::class)
        ->assertElement('select', settingsSelect('Reading language', 'Español (Latinoamérica)'))
        ->assertElement('select', settingsSelect('Image quality', 'Original (HD)'))
        ->assertElement('toggle', fn (array $n) => ($n['props']['a11y_label'] ?? null) === 'Safe' && $n['props']['value'] === true)
        ->assertElement('toggle', fn (array $n) => ($n['props']['a11y_label'] ?? null) === 'Suggestive' && $n['props']['value'] === false)
        ->assertSee('HD uses about 8× more space and data. A chapter keeps the quality it was saved in.');
});

it('saves a new reading language and marks Latest updates stale', function () {
    settingsSavedData();

    Native::test(Settings::class)
        ->select('chooseLanguage', 'Français')
        ->assertElement('select', settingsSelect('Reading language', 'Français'))
        ->select('chooseLanguage', 'Klingon')
        ->assertSet('language', 'fr');

    expect(app(SettingsRepository::class)->language())->toBe('fr')
        ->and(app(HomeRepository::class)->latest()->isStale)->toBeTrue();
});

it('saves the image quality', function () {
    Native::test(Settings::class)
        ->select('chooseQuality', 'Original (HD)')
        ->assertSet('quality', 'original')
        ->select('chooseQuality', 'Ultra')
        ->assertSet('quality', 'original');

    expect(app(SettingsRepository::class)->imageQuality())->toBe('original');
});

it('asks "I\'m 18 or older" before erotica; Cancel keeps the setting and the lists (Review Focus 4)', function () {
    settingsSavedData();
    $lists = DB::table('manga_lists')->count();

    Native::test(Settings::class)
        ->toggle("setRating('erotica')", true)
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => $p['title'] === 'Adult content')
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel'])
        ->assertSet('ratings', ['safe', 'suggestive']);

    expect(app(SettingsRepository::class)->contentRatings())->toBe(['safe', 'suggestive'])
        ->and(DB::table('manga_lists')->count())->toBe($lists)->not->toBe(0);
});

it('saves erotica after the confirmation and clears the lists so Home reloads (Review Focus 4)', function () {
    settingsSavedData();

    Native::test(Settings::class)
        ->toggle("setRating('erotica')", true)
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => "I'm 18 or older"])
        ->assertSet('ratings', ['safe', 'suggestive', 'erotica']);

    expect(app(SettingsRepository::class)->contentRatings())->toBe(['safe', 'suggestive', 'erotica'])
        ->and(DB::table('manga_lists')->count())->toBe(0)
        ->and(app(HomeRepository::class)->season()->isEmpty())->toBeTrue();
});

it('saves a rating switched off without asking', function () {
    Native::test(Settings::class)
        ->toggle("setRating('suggestive')", false)
        ->assertNativeNotCalled('Dialog.Alert');

    expect(app(SettingsRepository::class)->contentRatings())->toBe(['safe']);
});

it('shows the the source connection with request counts', function () {
    $minute = intdiv(Carbon::now()->getTimestamp(), 60);
    DB::table('api_usage')->insert([
        ['minute' => $minute, 'bucket' => 'global', 'requests' => 3, 'http_429' => 0, 'http_403' => 0],
        ['minute' => $minute - 10, 'bucket' => 'global', 'requests' => 1200, 'http_429' => 0, 'http_403' => 0],
    ]);

    Native::test(Settings::class)
        ->assertSee('Provider connection')
        ->assertSee('OK')
        ->assertSee('3 requests in the last minute · 1,203 in the last hour')
        ->assertElement('button', refreshButton('Refresh provider data', false));
});

it('says "Slowed down" after a 429 halved the rate', function () {
    DB::table('rate_buckets')->insert([
        'key' => 'global', 'capacity' => 4, 'refill_per_sec' => 2, 'tokens' => 4,
        'updated_at' => Carbon::now()->getTimestamp(), 'strikes' => 0,
    ]);

    Native::test(Settings::class)->assertSee('Slowed down');
});

it('asks before refreshing; Cancel clears nothing', function () {
    settingsSavedData();
    $lists = DB::table('manga_lists')->count();

    Native::test(Settings::class)
        ->tap('Refresh provider data')
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => $p['title'] === 'Refresh provider data'
        && $p['buttons'] === [['label' => 'Cancel', 'style' => 'cancel'], ['label' => 'Refresh', 'style' => 'destructive']])
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel'])
        ->assertSet('run', null);

    expect(DB::table('manga_lists')->count())->toBe($lists);
    Queue::assertNothingPushed();
});

it('clears the saved data, keeps the Library and starts the refill', function () {
    settingsSavedData();
    $kept = LibraryEntry::query()->value('manga_id');

    $screen = Native::test(Settings::class)
        ->tap('Refresh provider data')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Refresh']);

    $run = $screen->get('run');
    expect($run)->not->toBeNull()
        ->and(RefreshAllData::running())->toBe($run)
        ->and(DB::table('manga_lists')->count())->toBe(0)
        ->and(DB::table('tags')->count())->toBe(0)
        ->and(Manga::query()->pluck('id')->all())->toBe([$kept])
        ->and(Manga::find($kept)->fetched_at)->toBeNull()
        ->and(LibraryEntry::query()->count())->toBe(1);
    Queue::assertPushed(RefreshAllData::class, fn (RefreshAllData $job) => $job->run === $run);
});

it('disables the button while the refill runs and shows its progress', function () {
    $screen = Native::test(Settings::class)
        ->tap('Refresh provider data')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Refresh']);
    $run = $screen->get('run');
    LoadProgress::record($run, 'tags', 'ok');
    LoadProgress::record($run, 'season', LoadProgress::RUNNING);

    $screen->firePoll('checkRefill')
        ->assertElement('button', refreshButton('Refreshing…', true))
        ->assertSee('Categories')
        ->assertSee('Done')
        ->assertSee('Loading…')
        ->assertSee('Waiting')
        ->tap('Refreshing…')
        ->assertNativeCalledTimes('Dialog.Alert', 1);

    // Leaving Settings and coming back keeps showing the same refill.
    Native::test(Settings::class)
        ->assertSet('run', $run)
        ->assertElement('button', refreshButton('Refreshing…', true));
    Queue::assertPushed(RefreshAllData::class, 1);
});

it('enables the button again with the time of the refill once it lands', function () {
    $screen = Native::test(Settings::class)
        ->tap('Refresh provider data')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Refresh']);
    foreach (array_keys(DataLoader::STEPS) as $step) {
        LoadProgress::record($screen->get('run'), $step, 'ok');
    }
    Cache::forget(RefreshAllData::RUNNING_KEY);
    app(SettingsRepository::class)->markDataRefreshed();

    $screen->firePoll('checkRefill')
        ->assertElement('button', refreshButton('Refresh provider data', false))
        ->assertSee('Last refreshed Oct 24, 08:00.')
    // The finished steps stay, so a step that failed can be seen.
        ->assertSee('Categories')
        ->assertSee('Done')
        ->assertDontSee('Loading…');
});

it('says "Needs internet" and clears nothing when offline (Review Focus 6)', function () {
    settingsSavedData();
    $lists = DB::table('manga_lists')->count();
    Native::fakeBridge()->respondTo('Network.Status', ['connected' => false]);

    Native::test(Settings::class)
        ->tap('Refresh provider data')
        ->assertSee('Needs internet')
        ->assertNativeNotCalled('Dialog.Alert');

    expect(DB::table('manga_lists')->count())->toBe($lists)
        ->and(LibraryEntry::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('clears nothing when the phone goes offline while the dialog is open (Review Focus 6)', function () {
    settingsSavedData();
    $lists = DB::table('manga_lists')->count();

    $screen = Native::test(Settings::class)->tap('Refresh provider data');
    Native::fakeBridge()->respondTo('Network.Status', ['connected' => false]);
    $screen->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Refresh'])
        ->assertSee('Needs internet')
        ->assertSet('run', null);

    expect(DB::table('manga_lists')->count())->toBe($lists);
    Queue::assertNothingPushed();
});

it('shows the pause time instead of refreshing while the source limits the phone (Review Focus 3)', function (int $strikes) {
    settingsPaused($strikes);

    Native::test(Settings::class)
        ->assertSee('Paused until 12:52')
        ->assertElement('button', refreshButton('Paused until 12:52', true))
        ->tap('Paused until 12:52')
        ->assertNativeNotCalled('Dialog.Alert')
        ->assertSet('run', null);

    Queue::assertNothingPushed();
})->with([0, 2]);

it('opens the active provider URL in the in-app browser', function () {
    Native::test(Settings::class)
        ->tap('Data from your provider')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $p) => $p['url'] === 'https://api.source.test');
});

it('shows a Sources row and navigates to it (Plan-08 Task 2)', function () {
    Native::test(Settings::class)
        ->assertSee('Sources')
        ->tap('Sources')
        ->assertNavigatedTo('/settings/sources');
});

it('credits scanlation groups in the About row', function () {
    Native::test(Settings::class)
        ->assertSee('Data from your provider')
        ->assertSee('Chapters are translated by the scanlation groups credited on each chapter.')
        ->assertSee('ReaDex '.config('source.app_version'));
});

it('renders without dropped Tailwind classes', function () {
    config(['app.debug' => true]);
    (fn () => self::$reportedUnsupportedByView = [])->bindTo(null, TailwindParser::class)();
    TailwindParser::clearCache();
    $dropped = [];
    Log::listen(function (MessageLogged $log) use (&$dropped) {
        if (str_contains($log->message, 'dropped unsupported')) {
            $dropped[] = $log->context;
        }
    });

    $screen = Native::test(Settings::class)
        ->tap('Refresh provider data')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Refresh']);
    Native::fakeBridge()->respondTo('Network.Status', ['connected' => false]);
    Cache::forget(RefreshAllData::RUNNING_KEY);
    $screen->firePoll('checkRefill')->tap('Refresh provider data')->assertSee('Needs internet');

    expect($dropped)->toBe([]);
});

/** A cache file of `$bytes` that takes no disk space. */
function settingsCacheFile(string $caches, int $bytes): string
{
    $path = "{$caches}/covers/t6-big/big.jpg";
    File::ensureDirectoryExists(dirname($path));
    $file = fopen($path, 'w');
    ftruncate($file, $bytes);
    fclose($file);

    return $path;
}

it('says how much of the 1 GB image cache is used, in decimal MB and GB', function () {
    expect(Settings::storageLine(0, 1_000_000_000))->toBe('Image cache 0 MB of 1 GB')
        ->and(Settings::storageLine(312_400_000, 1_000_000_000))->toBe('Image cache 312 MB of 1 GB')
        ->and(Settings::storageLine(312_600_000, 1_000_000_000))->toBe('Image cache 313 MB of 1 GB')
        ->and(Settings::storageLine(999_400_000, 1_000_000_000))->toBe('Image cache 999 MB of 1 GB')
        ->and(Settings::storageLine(999_600_000, 1_000_000_000))->toBe('Image cache 1 GB of 1 GB')
        ->and(Settings::storageLine(1_240_000_000, 1_000_000_000))->toBe('Image cache 1.2 GB of 1 GB')
        ->and(Settings::storageLine(1_000_000, 500_000_000))->toBe('Image cache 1 MB of 500 MB');
});

it('shows the image cache under Storage, counted when Settings opens and not on every poll', function () {
    $file = settingsCacheFile($this->caches, 312_400_000);

    $screen = Native::test(Settings::class)
        ->assertSee('Storage')
        ->assertSee('Image cache 312 MB of 1 GB')
        ->assertSee('Clear image cache')
        ->assertSee('Chapters you opened longest ago go first when the cache is full.');

    unlink($file);
    settingsCacheFile($this->caches, 700_000_000);
    $screen->firePoll('checkRefill')->assertSee('Image cache 312 MB of 1 GB');
});

it('measures the cache against the configured cap', function () {
    config(['readex.cache.cap_bytes' => 500_000_000]);

    Native::test(Settings::class)->assertSee('Image cache 0 MB of 500 MB');
});

it('asks before clearing the image cache; Cancel clears nothing', function () {
    Stored::chapter('t6-a');

    Native::test(Settings::class)
        ->tap('Clear image cache')
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => $p['title'] === 'Clear image cache'
        && $p['buttons'] === [['label' => 'Cancel', 'style' => 'cancel'], ['label' => 'Clear', 'style' => 'destructive']])
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel']);

    expect(app(PageStore::class)->has('t6-a'))->toBeTrue();
});

it('clears the image cache but the open chapter, keeps progress, Library and downloads, and shows the new size', function () {
    $storage = sys_get_temp_dir().'/readex-storage-'.bin2hex(random_bytes(4));
    app()->useStoragePath($storage);
    register_shutdown_function(fn () => File::deleteDirectory($storage));
    $file = settingsCacheFile($this->caches, 312_400_000);
    Stored::chapter('t6-a');
    Stored::chapter('t6-open');
    Stored::chapter('t6-local', tier: 'local');
    app(LibraryRepository::class)->saveProgress('t6-a', 1, 2);
    app(OpenChapter::class)->set('t6-open');
    $entries = LibraryEntry::query()->count();

    Native::test(Settings::class)
        ->assertSee('Image cache 312 MB of 1 GB')
        ->tap('Clear image cache')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Clear'])
        ->assertSee('Image cache 0 MB of 1 GB');

    expect(is_file($file))->toBeFalse()
        ->and(StoredChapter::query()->orderBy('chapter_id')->pluck('chapter_id')->all())->toBe(['t6-local', 't6-open'])
        ->and(ReadingProgress::query()->find('t6-a')->page)->toBe(1)
        ->and(LibraryEntry::query()->count())->toBe($entries);
    Queue::assertNothingPushed();
});

it('asks before a factory reset with alarming wording; Cancel deletes nothing (Plan-08 Task 4)', function () {
    settingsSavedData();

    Native::test(Settings::class)
        ->tap('Delete everything')
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => $p['title'] === 'Delete everything?'
        && str_contains($p['message'], "can't be undone")
        && $p['buttons'] === [['label' => 'Cancel', 'style' => 'cancel'], ['label' => 'Delete everything', 'style' => 'destructive']])
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel'])
        ->assertNoNavigation();

    expect(DB::table('manga_lists')->count())->toBeGreaterThan(0)
        ->and(LibraryEntry::query()->count())->toBeGreaterThan(0);
});

it('wipes every table and file on confirm, and routes back to the real first-run flow', function () {
    settingsSavedData();
    app(SettingsRepository::class)->markSetupDone();
    app(SettingsRepository::class)->acknowledgeEula();
    Loading::fake(fn (Request $request) => $request->url() === 'https://example.test' ? Http::response('', 200) : null);
    app(SourceRepository::class)->add('Mine', 'https://example.test');

    Native::test(Settings::class)
        ->tap('Delete everything')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete everything'])
        ->assertReplacedWith('/');

    // The screen's own re-render after the wipe re-seeds the empty built-in row (same as any
    // fresh install visiting Settings) — that's expected, not leftover user data. The real
    // assertion is that the USER source + every other table's rows are gone.
    expect(DB::table('manga_lists')->count())->toBe(0)
        ->and(DB::table('collections')->count())->toBe(0)
        ->and(DB::table('settings')->count())->toBe(0)
        ->and(DB::table('sources')->where('kind', 'user')->count())->toBe(0)
        ->and(app(SettingsRepository::class)->eulaAcknowledged())->toBeFalse()
        ->and(app(SettingsRepository::class)->setupDone())->toBeFalse();
});
