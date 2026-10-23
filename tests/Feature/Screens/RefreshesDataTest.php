<?php

use App\Jobs\RefreshRepository;
use App\MangaDex\Exceptions\ApiError;
use App\MangaDex\Exceptions\RequestDeferred;
use App\MangaDex\RateLimit\Busy;
use App\MangaDex\RateLimit\BusyReason;
use App\MangaDex\RateLimit\Priority;
use App\Models\Tag;
use App\Refresh\Landed;
use App\Refresh\Outcome;
use App\Refresh\Refresh;
use App\Refresh\RefreshStatus;
use App\Refresh\RefreshTask;
use App\Repositories\TagRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Testing\Native;
use Tests\Support\FakeRefreshTarget;
use Tests\Support\MangaDexFixtures;
use Tests\Support\RefreshingScreen;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
    FakeRefreshTarget::reset();
    $this->async = AsyncTask::fake();
    Queue::fake();
    Native::fakeBridge()->respondTo('MdxStorage.TimeZone', ['zone' => 'America/Toronto']);
});

afterEach(fn () => AsyncTask::clearFake());

function deferred(BusyReason $reason, string $until = '2026-10-23 18:35:00'): RequestDeferred
{
    return new RequestDeferred(new Busy(CarbonImmutable::parse($until, 'UTC')->getTimestampMs() / 1000, $reason));
}

function staleRow(): void
{
    FakeRefreshTarget::reset(['saved'], CarbonImmutable::parse('2026-10-23 18:00:00', 'UTC'));
}

it('starts no task when the saved data is fresh', function () {
    FakeRefreshTarget::reset(['saved'], CarbonImmutable::parse('2026-10-23 18:25:00', 'UTC'));

    Native::test(RefreshingScreen::class)
        ->assertSet('items', ['saved'])
        ->assertSee('All good');

    $this->async->assertNotDispatched();
});

it('refreshes stale data on the async lane at interactive priority, then reloads', function () {
    staleRow();

    Native::test(RefreshingScreen::class)
        ->assertSet('items', ['fresh'])
        ->assertSet('loads', 2)
        ->assertSet('refreshing', [])
        ->assertSee('All good');

    $this->async->assertDispatchedTimes(1)
        ->assertDispatched(fn (array $d) => $d['work']['task'] === RefreshTask::class);
    expect(FakeRefreshTarget::$calls)->toBe([['latest', Priority::Interactive]]);
});

it('refreshes empty data even without a fetched_at', function () {
    Native::test(RefreshingScreen::class)->assertSet('items', ['fresh']);

    expect(FakeRefreshTarget::$calls)->toHaveCount(1);
});

it('does not loop when a refresh succeeds but the data stays empty', function () {
    FakeRefreshTarget::$script = [[], []];

    $screen = Native::test(RefreshingScreen::class)->assertSet('items', []);
    expect(FakeRefreshTarget::$calls)->toHaveCount(1);

    $screen->call('onResume');
    expect(FakeRefreshTarget::$calls)->toHaveCount(1);

    $this->travel(61)->seconds();
    $screen->call('onResume');
    expect(FakeRefreshTarget::$calls)->toHaveCount(2);
});

it('restarts a refresh whose callback was dropped while another screen was on top', function () {
    FakeRefreshTarget::reset(['saved'], CarbonImmutable::parse('2026-10-23 18:25:00', 'UTC'));
    $key = Refresh::of([FakeRefreshTarget::class, 'refresh'], 'latest')->key();
    $screen = Native::test(RefreshingScreen::class)->set('refreshing', [$key => true]);
    staleRow();

    $screen->call('onResume')->assertSet('items', ['fresh']);

    expect(FakeRefreshTarget::$calls)->toHaveCount(1);
});

it('keeps saved data and says offline when there is no connection', function () {
    staleRow();
    FakeRefreshTarget::$script = [new ConnectionException('cURL error 6')];

    $screen = Native::test(RefreshingScreen::class)
        ->assertSet('items', ['saved'])
        ->assertSee('Offline, showing saved data');

    Queue::assertNothingPushed();

    $screen->call('onResume');
    expect(FakeRefreshTarget::$calls)->toHaveCount(1);

    $screen->call('pullToRefresh')
        ->assertSet('items', ['fresh'])
        ->assertSee('All good');
    expect(FakeRefreshTarget::$calls)->toHaveCount(2);
});

it('retries an offline refresh from the poll once the connection had time to come back', function () {
    staleRow();
    FakeRefreshTarget::$script = [new ConnectionException('cURL error 28')];

    $screen = Native::test(RefreshingScreen::class)->assertSee('Offline, showing saved data');

    $this->travel(14)->seconds();
    $screen->call('checkWaitingRefreshes');
    expect(FakeRefreshTarget::$calls)->toHaveCount(1);

    $this->travel(1)->seconds();
    $screen->call('checkWaitingRefreshes')
        ->assertSet('items', ['fresh'])
        ->assertSet('problems', [])
        ->assertSet('offlineAt', [])
        ->assertSee('All good');
    expect(FakeRefreshTarget::$calls)->toHaveCount(2);
});

it('keeps the offline line while a retry fails again, and waits another 15 seconds', function () {
    staleRow();
    FakeRefreshTarget::$script = [new ConnectionException('down'), new ConnectionException('down')];

    $screen = Native::test(RefreshingScreen::class);

    $this->travel(15)->seconds();
    $screen->call('checkWaitingRefreshes')->assertSee('Offline, showing saved data');
    expect(FakeRefreshTarget::$calls)->toHaveCount(2);

    $this->travel(14)->seconds();
    $screen->call('checkWaitingRefreshes');
    expect(FakeRefreshTarget::$calls)->toHaveCount(2);

    $this->travel(1)->seconds();
    $screen->call('checkWaitingRefreshes')->assertSet('items', ['fresh']);
    expect(FakeRefreshTarget::$calls)->toHaveCount(3);
});

it('does not retry while the phone says it has no connection', function () {
    staleRow();
    FakeRefreshTarget::$script = [new ConnectionException('down')];
    Native::fakeBridge()->respondTo('Network.Status', ['connected' => false]);

    $screen = Native::test(RefreshingScreen::class);

    $this->travel(15)->seconds();
    $screen->call('checkWaitingRefreshes')->assertSee('Offline, showing saved data');
    expect(FakeRefreshTarget::$calls)->toHaveCount(1);

    Native::fakeBridge()->respondTo('Network.Status', ['connected' => true]);
    $this->travel(14)->seconds();
    $screen->call('checkWaitingRefreshes');
    expect(FakeRefreshTarget::$calls)->toHaveCount(1);

    $this->travel(1)->seconds();
    $screen->call('checkWaitingRefreshes')->assertSet('items', ['fresh']);
    expect(FakeRefreshTarget::$calls)->toHaveCount(2);
});

it('leaves a failed refresh for pull to refresh', function () {
    staleRow();
    FakeRefreshTarget::$script = [new RuntimeException('boom')];
    Exceptions::fake();

    $screen = Native::test(RefreshingScreen::class)->assertSee('Something went wrong');

    $this->travel(5)->minutes();
    $screen->call('checkWaitingRefreshes');
    expect(FakeRefreshTarget::$calls)->toHaveCount(1);
});

it('says busy and queues the retry job when there is no request slot', function () {
    staleRow();
    FakeRefreshTarget::$script = [deferred(BusyReason::Saturated)];

    $screen = Native::test(RefreshingScreen::class)
        ->assertSet('items', ['saved'])
        ->assertSee('MangaDex is busy, will update shortly');

    Queue::assertPushed(RefreshRepository::class, fn (RefreshRepository $job) => $job->refresh->method === 'refresh'
        && $job->refresh->args === ['latest']);
    expect($screen->get('waiting'))->toHaveCount(1);

    $screen->call('onResume');
    expect(FakeRefreshTarget::$calls)->toHaveCount(1);
});

it('shows when MangaDex stops limiting the phone, in the phone time zone', function (BusyReason $reason) {
    staleRow();
    FakeRefreshTarget::$script = [deferred($reason)];

    Native::test(RefreshingScreen::class)
        ->assertSee('MangaDex is limiting this phone, try after 14:35');

    Queue::assertPushed(RefreshRepository::class);
})->with([BusyReason::Throttled, BusyReason::Banned]);

it('says something went wrong for any other error, without a crash screen', function () {
    Exceptions::fake();
    staleRow();
    FakeRefreshTarget::$script = [new ApiError(500, 'MangaDex HTTP 500: boom')];

    Native::test(RefreshingScreen::class)
        ->assertSet('items', ['saved'])
        ->assertSee('Something went wrong')
        ->assertDontSee('boom');

    Exceptions::assertReported(ApiError::class);
    Queue::assertNothingPushed();
});

it('picks up the job result on the next poll and reloads', function () {
    staleRow();
    FakeRefreshTarget::$script = [deferred(BusyReason::Saturated)];
    $screen = Native::test(RefreshingScreen::class)->assertSee('MangaDex is busy');

    $screen->firePoll('checkWaitingRefreshes')->assertSee('MangaDex is busy');

    $this->travel(5)->seconds();
    (new RefreshRepository(Refresh::of([FakeRefreshTarget::class, 'refresh'], 'latest')))->handle();

    $screen->firePoll('checkWaitingRefreshes')
        ->assertSet('items', ['fresh'])
        ->assertSet('waiting', [])
        ->assertSee('All good');
    expect(FakeRefreshTarget::$calls[1])->toBe(['latest', Priority::Prefetch]);
});

it('ignores a job result from before the screen started waiting', function () {
    $refresh = Refresh::of([FakeRefreshTarget::class, 'refresh'], 'latest');
    FakeRefreshTarget::reset(['old']);
    Landed::put($refresh, Outcome::failed());
    $this->travel(1)->seconds();

    staleRow();
    FakeRefreshTarget::$script = [deferred(BusyReason::Saturated)];

    Native::test(RefreshingScreen::class)
        ->firePoll('checkWaitingRefreshes')
        ->assertSee('MangaDex is busy');
});

it('shows offline before limited before busy before failed', function () {
    $screen = Native::test(RefreshingScreen::class)->set('problems', [
        'a' => ['status' => 'failed', 'retry_at' => null, 'result' => null],
        'b' => ['status' => 'busy', 'retry_at' => null, 'result' => null],
        'c' => ['status' => 'offline', 'retry_at' => null, 'result' => null],
    ]);

    expect($screen->instance()->statusLine())->toBe('Offline, showing saved data');
});

describe('RefreshRepository job', function () {
    beforeEach(function () {
        $this->refresh = Refresh::of([FakeRefreshTarget::class, 'refresh'], 'latest');
    });

    it('waits for the controller retry time when deferred again', function (BusyReason $reason) {
        FakeRefreshTarget::$script = [deferred($reason, '2026-10-23 18:31:30.250')];
        $job = (new RefreshRepository($this->refresh))->withFakeQueueInteractions();

        $job->handle();

        $job->assertReleased(delay: 91);
        expect(Landed::since($this->refresh->key(), 0))->toBeNull();
    })->with([BusyReason::Saturated, BusyReason::Throttled, BusyReason::Banned]);

    it('records success, offline and errors as final', function (array $script, RefreshStatus $status) {
        Exceptions::fake();
        FakeRefreshTarget::$script = $script;
        $job = (new RefreshRepository($this->refresh))->withFakeQueueInteractions();

        $job->handle();

        $job->assertNotReleased();
        expect(Landed::since($this->refresh->key(), 0)->status)->toBe($status);
    })->with([
        'ok' => [[['fresh']], RefreshStatus::Ok],
        'offline' => [[new ConnectionException('down')], RefreshStatus::Offline],
        'error' => [[new RuntimeException('boom')], RefreshStatus::Failed],
    ]);

    it('records a failure when the queue gives up', function () {
        (new RefreshRepository($this->refresh))->failed(new RuntimeException('max attempts'));

        expect(Landed::since($this->refresh->key(), 0)->status)->toBe(RefreshStatus::Failed);
    });

    it('is queued once per refresh and gives up after an hour', function () {
        RefreshRepository::dispatch($this->refresh);
        RefreshRepository::dispatch(Refresh::of([FakeRefreshTarget::class, 'refresh'], 'latest'));
        RefreshRepository::dispatch(Refresh::of([FakeRefreshTarget::class, 'refresh'], 'popular'));

        Queue::assertPushed(RefreshRepository::class, 2);
        expect((new RefreshRepository($this->refresh))->retryUntil()->equalTo(Carbon::now()->addHour()))->toBeTrue();
    });
});

describe('Refresh', function () {
    it('runs a real repository method with the given priority', function () {
        MangaDexFixtures::fake('tags');

        $outcome = Outcome::capture(fn () => Refresh::of([TagRepository::class, 'refresh'])->run(Priority::Interactive));

        expect($outcome->status)->toBe(RefreshStatus::Ok)
            ->and(Tag::count())->toBe(77);
    });

    it('keeps the result of methods that return one', function () {
        $outcome = Outcome::fromArray(Outcome::capture(fn () => true)->toArray());

        expect($outcome->result)->toBeTrue();
    });

    it('has one key per repository, method and arguments', function () {
        $a = Refresh::of([FakeRefreshTarget::class, 'refresh'], 'latest');

        expect($a->key())->toBe(Refresh::of([FakeRefreshTarget::class, 'refresh'], 'latest')->key())
            ->not->toBe(Refresh::of([FakeRefreshTarget::class, 'refresh'], 'popular')->key());
    });

    it('rejects unknown methods and arguments that are not JSON values', function () {
        expect(fn () => Refresh::of([FakeRefreshTarget::class, 'nope']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Refresh::of([FakeRefreshTarget::class, 'refresh'], Priority::Bulk))->toThrow(InvalidArgumentException::class);
    });
});

it('draws the status line without dropped Tailwind classes', function () {
    config(['app.debug' => true]);
    (fn () => self::$reportedUnsupportedByView = [])->bindTo(null, TailwindParser::class)();
    TailwindParser::clearCache();
    $dropped = [];
    Log::listen(function (MessageLogged $log) use (&$dropped) {
        if (str_contains($log->message, 'dropped unsupported')) {
            $dropped[] = $log->context;
        }
    });

    Native::test(RefreshingScreen::class)->assertSee('All good');

    expect($dropped)->toBe([]);
});
