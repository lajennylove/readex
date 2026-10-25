<?php

use App\Loading\LoadProgress;
use App\Loading\LoadTask;
use App\NativeComponents\Setup;
use App\Refresh\RefreshTask;
use App\Repositories\HomeRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;
use App\Storage\LocalStorageBridge;
use App\Storage\NativeStorageBridge;
use App\Storage\StorageBridge;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Support\Covers;
use Tests\Support\Loading;

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    // The phone bridge (preferred language) with temp caches behind it.
    Covers::useTempCaches();
    app()->instance(StorageBridge::class, new NativeStorageBridge(new LocalStorageBridge(dirname(app(StorageBridge::class)->cachesPath()))));
    $this->async = AsyncTask::fake();
    $this->bridge = Native::fakeBridge()->respondTo('MdxStorage.PreferredLanguage', ['language' => 'es-MX']);
    Loading::fake();
});

afterEach(fn () => AsyncTask::clearFake());

function languagePicker(string $value): Closure
{
    return fn (array $node) => ($node['props']['label'] ?? null) === 'Reading language' && ($node['props']['value'] ?? null) === $value;
}

/** Node id of a rating toggle in the last render. */
function toggleId(array $node, string $label): ?int
{
    if (($node['type'] ?? null) === 'toggle' && ($node['props']['a11y_label'] ?? null) === $label) {
        return $node['id'];
    }

    foreach ($node['children'] ?? [] as $child) {
        if (($id = toggleId($child, $label)) !== null) {
            return $id;
        }
    }

    return null;
}

function ratingToggle(string $label, bool $on): Closure
{
    return fn (array $node) => ($node['props']['a11y_label'] ?? null) === $label && ($node['props']['value'] ?? null) === $on;
}

/** Types MangaDex's own API URL into the source field — the shortest path through Continue in most tests. */
function withBuiltInSource(TestableComponent $screen): TestableComponent
{
    return $screen->call('changeSourceUrl', config('source.api_url'));
}

it('labels the rating toggles with theme text, since the iOS toggle label ignores the theme', function () {
    $screen = Native::test(Setup::class);

    foreach (['Safe', 'Suggestive', 'Erotica', 'Pornographic'] as $label) {
        $screen->assertElement('text', fn (array $node) => ($node['props']['text'] ?? null) === $label
            && ($node['props']['color'] ?? null) === config('native-ui.theme.dark.on-background'));
    }

    $screen->assertMissingElement('toggle', fn (array $node) => isset($node['props']['label']));
});

it('preselects Français on a fr-CA phone (spec §10)', function () {
    Native::fakeBridge()->respondTo('MdxStorage.PreferredLanguage', ['language' => 'fr-CA']);

    Native::test(Setup::class)
        ->assertSet('language', 'fr')
        ->assertElement('select', languagePicker('Français'));
});

it('preselects English when the phone language is unknown', function () {
    Native::fakeBridge()->respondTo('MdxStorage.PreferredLanguage', []);

    Native::test(Setup::class)->assertElement('select', languagePicker('English'));
});

it('preselects safe and suggestive', function () {
    Native::test(Setup::class)
        ->assertSet('ratings', ['safe', 'suggestive'])
        ->assertElement('toggle', ratingToggle('Safe', true))
        ->assertElement('toggle', ratingToggle('Suggestive', true))
        ->assertElement('toggle', ratingToggle('Erotica', false))
        ->assertElement('toggle', ratingToggle('Pornographic', false));
});

it('loads tags in the background while the reader picks', function () {
    Native::test(Setup::class);

    $this->async->assertDispatchedTimes(1)
        ->assertDispatched(fn (array $d) => $d['work']['task'] === RefreshTask::class);
    expect(DB::table('tags')->count())->toBe(77);
});

it('picks a language from the native names', function () {
    Native::test(Setup::class)
        ->select('chooseLanguage', 'Español (España)')
        ->assertSet('language', 'es')
        ->assertElement('select', languagePicker('Español (España)'))
        ->select('chooseLanguage', 'Klingon')
        ->assertSet('language', 'es');
});

it('asks "I\'m 18 or older" before showing erotica; Cancel keeps the old set (Review Focus 4)', function () {
    $screen = Native::test(Setup::class)
        ->toggle("setRating('erotica')", true)
        ->assertSet('ratings', ['safe', 'suggestive'])
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => $p['title'] === 'Adult content'
            && $p['buttons'] === [['label' => 'Cancel', 'style' => 'cancel'], "I'm 18 or older"]);

    $flipped = toggleId($screen->tree(), 'Erotica');

    $screen->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel'])
        ->assertSet('ratings', ['safe', 'suggestive'])
        ->assertElement('toggle', ratingToggle('Erotica', false));

    // A new node, so the phone redraws the toggle it already flipped on.
    expect(toggleId($screen->tree(), 'Erotica'))->not->toBe($flipped);
});

it('adds erotica after the confirmation and asks only once for adult ratings', function () {
    Native::test(Setup::class)
        ->toggle("setRating('erotica')", true)
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => "I'm 18 or older"])
        ->assertSet('ratings', ['safe', 'suggestive', 'erotica'])
        ->toggle("setRating('pornographic')", true)
        ->assertSet('ratings', ['safe', 'suggestive', 'erotica', 'pornographic'])
        ->assertNativeCalledTimes('Dialog.Alert', 1);
});

it('keeps at least one rating on', function () {
    Native::test(Setup::class)
        ->toggle("setRating('suggestive')", false)
        ->assertSet('ratings', ['safe'])
        ->toggle("setRating('safe')", false)
        ->assertSet('ratings', ['safe'])
        ->assertSet('ratingToggles', 1)
        ->assertNativeNotCalled('Dialog.Alert');
});

it('saves the choices, loads everything and opens Home', function () {
    $screen = withBuiltInSource(Native::test(Setup::class))
        ->select('chooseLanguage', 'Español (Latinoamérica)')
        ->tap('Continue')
        ->assertReplacedWith('/home');

    $settings = app(SettingsRepository::class);
    expect($settings->setupDone())->toBeTrue()
        ->and($settings->language())->toBe('es-la')
        ->and($settings->contentRatings())->toBe(['safe', 'suggestive'])
        ->and(app(HomeRepository::class)->latest()->isEmpty())->toBeFalse()
        ->and($screen->get('steps'))->toBe(['tags' => 'ok', 'season' => 'ok', 'popular' => 'ok', 'latest' => 'ok', 'covers' => 'ok']);
    $this->async->assertDispatched(fn (array $d) => $d['work']['task'] === LoadTask::class);

    expect(app(SourceRepository::class)->builtInEnabled())->toBeTrue();
});

it('saves the content rating the reader picked', function () {
    withBuiltInSource(Native::test(Setup::class))
        ->toggle("setRating('suggestive')", false)
        ->tap('Continue')
        ->assertReplacedWith('/home');

    expect(app(SettingsRepository::class)->contentRatings())->toBe(['safe']);
});

it('keeps the reader on Setup with an error when the source field is left empty (Jenny: you need to add your API source)', function () {
    Native::test(Setup::class)
        ->tap('Continue')
        ->assertSet('phase', Setup::SETTINGS)
        ->assertSee('Not a valid URL.')
        ->assertNoNavigation();

    expect(app(SettingsRepository::class)->setupDone())->toBeFalse();
});

it('keeps the reader on Setup with an error when the source URL fails its health check', function () {
    Loading::fake(fn (Request $request) => $request->url() === 'https://down.example.test' ? Http::response('', 503) : null);

    Native::test(Setup::class)
        ->call('changeSourceUrl', 'https://down.example.test')
        ->tap('Continue')
        ->assertSet('phase', Setup::SETTINGS)
        ->assertSee("Couldn't connect to that source. Check the URL and try again.")
        ->assertNoNavigation();

    expect(app(SettingsRepository::class)->setupDone())->toBeFalse();
});

it('saves whatever other source URL the reader typed, labeled by its host, not just MangaDex', function () {
    Loading::fake(fn (Request $request) => $request->url() === 'https://example.test/api' ? Http::response('', 200) : null);

    Native::test(Setup::class)
        ->call('changeSourceUrl', 'https://example.test/api')
        ->tap('Continue')
        ->assertReplacedWith('/home');

    $source = app(SourceRepository::class)->all()->firstWhere('kind', 'user');
    expect($source->label)->toBe('example.test')
        ->and($source->config['base_url'])->toBe('https://example.test/api');
});

it('says "First launch needs internet" and stays in Setup when offline (Review Focus 1)', function () {
    Native::fakeBridge()->respondTo('Network.Status', ['connected' => false]);

    $screen = withBuiltInSource(Native::test(Setup::class))
        ->tap('Continue')
        ->assertSet('phase', Setup::OFFLINE)
        ->assertSee('First launch needs internet')
        ->assertSee('Retry')
        ->assertNoNavigation();

    expect(app(SettingsRepository::class)->setupDone())->toBeFalse();
    // The background tags task from mount(), plus the source health check.
    $this->async->assertDispatchedTimes(1);

    Native::fakeBridge()->respondTo('Network.Status', ['connected' => true]);
    $screen->tap('Retry')->assertReplacedWith('/home');
});

it('also catches no connection when the phone claims to be online', function () {
    Loading::fake(fn () => throw new ConnectionException('cURL error 6'));

    withBuiltInSource(Native::test(Setup::class))
        ->tap('Continue')
        ->assertSee("Couldn't connect to that source. Check the URL and try again.")
        ->assertNoNavigation();

    expect(app(SettingsRepository::class)->setupDone())->toBeFalse();
});

it('opens Home when rows load but one keeps failing', function () {
    Loading::fake(fn (Request $request) => parse_url($request->url(), PHP_URL_HOST) === 'antsylich.github.io' ? Http::response('down', 503) : null);

    $screen = withBuiltInSource(Native::test(Setup::class))->tap('Continue')->assertReplacedWith('/home');

    expect($screen->get('steps')['season'])->toBe('unavailable');
});

it('shows progress per step while loading', function () {
    $screen = Native::test(Setup::class)
        ->set('phase', Setup::LOADING)
        ->set('run', 'r1')
        ->set('startedAt', Carbon::now()->getTimestampMs());
    LoadProgress::start('r1');
    LoadProgress::record('r1', 'tags', 'ok');
    LoadProgress::record('r1', 'season', LoadProgress::RUNNING);

    $screen->firePoll('checkLoading')
        ->assertSee('Categories')
        ->assertSee('Done')
        ->assertSee('Loading…')
        ->assertSee('Waiting')
        ->assertNoNavigation();
});

it('opens Home after 10 s with what loaded while a row hangs (Review Focus 2)', function () {
    $screen = Native::test(Setup::class)
        ->set('phase', Setup::LOADING)
        ->set('run', 'r1')
        ->set('startedAt', Carbon::now()->getTimestampMs());
    LoadProgress::start('r1');
    foreach (['tags' => 'ok', 'season' => 'running'] as $step => $state) {
        LoadProgress::record('r1', $step, $state);
    }

    Carbon::setTestNow(Carbon::now()->addSeconds(12));
    $screen->firePoll('checkLoading')->assertNoNavigation();

    LoadProgress::record('r1', 'season', 'ok');
    LoadProgress::record('r1', 'popular', LoadProgress::RUNNING);
    Carbon::setTestNow('2026-10-24 12:00:09');
    $screen->set('startedAt', Carbon::parse('2026-10-24 12:00:00')->getTimestampMs())
        ->firePoll('checkLoading')->assertNoNavigation();

    Carbon::setTestNow('2026-10-24 12:00:10');
    $screen->firePoll('checkLoading')->assertReplacedWith('/home');

    expect(app(SettingsRepository::class)->setupDone())->toBeTrue();
});

it('opens Home only once when the task finishes after the cut-off', function () {
    $screen = Native::test(Setup::class)->set('phase', Setup::DONE);

    (fn () => $this->loadingFinished(['season' => 'ok']))->call($screen->instance());

    $screen->assertNoNavigation();
});

it('renders every phase without dropped Tailwind classes', function () {
    config(['app.debug' => true]);
    (fn () => self::$reportedUnsupportedByView = [])->bindTo(null, TailwindParser::class)();
    TailwindParser::clearCache();
    $dropped = [];
    Log::listen(function (MessageLogged $log) use (&$dropped) {
        if (str_contains($log->message, 'dropped unsupported')) {
            $dropped[] = $log->context;
        }
    });

    foreach ([Setup::LOADING, Setup::OFFLINE] as $phase) {
        Native::test(Setup::class)->set('phase', $phase)->set('run', 'r1');
    }

    expect($dropped)->toBe([]);
});
