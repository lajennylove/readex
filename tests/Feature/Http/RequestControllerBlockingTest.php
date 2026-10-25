<?php

use App\Adapters\Http\RateLimit\Busy;
use App\Adapters\Http\RateLimit\BusyReason;
use App\Adapters\Http\RateLimit\Call;
use App\Adapters\Http\RateLimit\ConnectionState;
use App\Adapters\Http\RateLimit\Priority;
use App\Adapters\Http\RateLimit\RequestController;
use App\Adapters\Http\RateLimit\Slot;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

const B0 = 1_792_756_800.0; // 2026-10-23 12:00:00 UTC

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(B0));
    Sleep::fake(syncWithCarbon: true);
    $this->rc = app(RequestController::class);
    $this->api = fn (string $path = '/manga') => Call::get('https://api.mangadex.org'.$path);
    $this->at = function (float $t): void {
        Carbon::setTestNow(Carbon::createFromTimestamp(B0)->addMicroseconds((int) round($t * 1_000_000)));
    };
    $this->reply = fn (int $status, array $headers = [], string $body = '{"result":"ok"}') => new Response(new Psr7Response($status, $headers, $body));
    $this->ban = fn () => new Response(new Psr7Response(403, ['Content-Type' => 'text/html'], '<html>Forbidden</html>'));
});

describe('429', function () {
    it('blocks every mangadex.org bucket until the retry time', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);

        $this->rc->record($slot, ($this->reply)(429, ['X-RateLimit-Retry-After' => (string) (B0 + 20)]));

        $api = $this->rc->acquire(($this->api)('/manga/abc'), Priority::Interactive, 0);
        $cover = $this->rc->acquire(Call::get('https://uploads.mangadex.org/covers/m/c.jpg.256.jpg'), Priority::Interactive, 0);
        $image = $this->rc->acquire(Call::get('https://node.mangadex.network/data/h/1.png'), Priority::Interactive, 0);

        expect($api)->toBeInstanceOf(Busy::class)
            ->and($api->reason)->toBe(BusyReason::Throttled)
            ->and($api->retryAt)->toBe(B0 + 20)
            ->and($cover)->toBeInstanceOf(Busy::class)
            ->and($image)->toBeInstanceOf(Slot::class);
    });

    it('waits 60 seconds when MangaDex sends no retry header', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);

        $this->rc->record($slot, ($this->reply)(429));

        expect($this->rc->acquire(($this->api)(), Priority::Interactive, 0)->retryAt)->toBe(B0 + 60);
    });

    it('uses a numeric Retry-After header', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);

        $this->rc->record($slot, ($this->reply)(429, ['Retry-After' => '7']));

        expect($this->rc->acquire(($this->api)(), Priority::Interactive, 0)->retryAt)->toBe(B0 + 7);
    });

    it('halves the global rate after the pause and recovers slowly', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        $this->rc->record($slot, ($this->reply)(429, ['X-RateLimit-Retry-After' => (string) (B0 + 20)]));

        ($this->at)(20);
        $busy = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        expect($busy->retryAt)->toEqualWithDelta(B0 + 20.5, 0.001)
            ->and($this->rc->status()->state)->toBe(ConnectionState::Slowed);

        ($this->at)(80);
        $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        expect((float) DB::table('rate_buckets')->where('key', 'global')->value('refill_per_sec'))->toBe(2.5);

        ($this->at)(260);
        $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        expect((float) DB::table('rate_buckets')->where('key', 'global')->value('refill_per_sec'))->toBe(4.0)
            ->and($this->rc->status()->state)->toBe(ConnectionState::Ok);
    });

    it('also slows the background share', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Bulk, 0);
        $this->rc->record($slot, ($this->reply)(429, ['X-RateLimit-Retry-After' => (string) (B0 + 20)]));

        expect((float) DB::table('rate_buckets')->where('key', 'global:background')->value('refill_per_sec'))->toBe(1.5);
    });
});

describe('403 ban', function () {
    it('stops every mangadex.org call for 15 minutes', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);

        $this->rc->record($slot, ($this->ban)());

        $busy = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        expect($busy->reason)->toBe(BusyReason::Banned)
            ->and($busy->retryAt)->toBe(B0 + 900)
            ->and($this->rc->status()->banned)->toBeTrue();
    });

    it('treats a JSON API error 403 as a normal error, not a ban', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);

        $this->rc->record($slot, ($this->reply)(403, ['Content-Type' => 'application/json'], '{"result":"error","errors":[{"status":403,"title":"Forbidden"}]}'));

        expect($this->rc->acquire(($this->api)(), Priority::Interactive, 0))->toBeInstanceOf(Slot::class);
    });

    it('sends exactly one probe after the ban, and doubles the ban if it is still on', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        $this->rc->record($slot, ($this->ban)());

        ($this->at)(900);
        $probe = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        $second = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);

        expect($probe)->toBeInstanceOf(Slot::class)
            ->and($probe->isProbe)->toBeTrue()
            ->and($second)->toBeInstanceOf(Busy::class);

        $this->rc->record($probe, ($this->ban)());
        expect($this->rc->acquire(($this->api)(), Priority::Interactive, 0)->retryAt)->toBe(B0 + 900 + 1800);
    });

    it('caps the ban at 2 hours', function () {
        DB::table('rate_buckets')->insert([
            'key' => 'family:mangadex.org', 'capacity' => 0, 'refill_per_sec' => 0, 'tokens' => 0,
            'updated_at' => B0, 'blocked_until' => null, 'learned_limit' => null, 'strikes' => 5, 'probing' => true, 'adjusted_at' => B0,
        ]);
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);

        $this->rc->record($slot, ($this->ban)());

        expect($this->rc->acquire(($this->api)(), Priority::Interactive, 0)->retryAt)->toBe(B0 + 7200);
    });

    it('clears the ban when the probe gets a normal answer', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        $this->rc->record($slot, ($this->ban)());
        ($this->at)(900);
        $probe = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);

        $this->rc->record($probe, ($this->reply)(200));

        expect($this->rc->acquire(($this->api)(), Priority::Interactive, 0))->toBeInstanceOf(Slot::class)
            ->and($this->rc->status()->state)->toBe(ConnectionState::Ok)
            ->and((int) DB::table('rate_buckets')->where('key', 'family:mangadex.org')->value('strikes'))->toBe(0);
    });

    it('sends another single probe when the first one never reports back (lost probe)', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        $this->rc->record($slot, ($this->ban)());
        ($this->at)(900);
        $this->rc->acquire(($this->api)(), Priority::Interactive, 0);

        ($this->at)(929);
        expect($this->rc->acquire(($this->api)(), Priority::Interactive, 0))->toBeInstanceOf(Busy::class);

        ($this->at)(930);
        $again = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        expect($again->isProbe)->toBeTrue()
            ->and($this->rc->acquire(($this->api)(), Priority::Interactive, 0))->toBeInstanceOf(Busy::class);
    });

    it('does not let a job sleep through a ban when maxWait is short', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        $this->rc->record($slot, ($this->ban)());

        $busy = $this->rc->acquire(($this->api)(), Priority::Bulk, 2.0);

        expect($busy)->toBeInstanceOf(Busy::class);
        Sleep::assertNeverSlept();
    });
});

describe('usage and status', function () {
    it('counts requests per minute and keeps 24 hours', function () {
        DB::table('api_usage')->insert(['minute' => (int) floor(B0 / 60) - 1441, 'bucket' => 'global', 'requests' => 9, 'http_429' => 0, 'http_403' => 0]);

        foreach (range(1, 3) as $i) {
            $this->rc->record($this->rc->acquire(($this->api)(), Priority::Interactive, 0), ($this->reply)(200));
        }

        $status = $this->rc->status();
        expect($status->requestsLastMinute)->toBe(3)
            ->and($status->requestsLastHour)->toBe(3)
            ->and(DB::table('api_usage')->count())->toBe(1);
    });

    it('reports the pause time', function () {
        $slot = $this->rc->acquire(($this->api)(), Priority::Interactive, 0);
        $this->rc->record($slot, ($this->reply)(429, ['X-RateLimit-Retry-After' => (string) (B0 + 20)]));

        $status = $this->rc->status();

        expect($status->state)->toBe(ConnectionState::Paused)
            ->and($status->pausedUntil->getTimestamp())->toBe((int) (B0 + 20))
            ->and($status->banned)->toBeFalse()
            ->and((int) DB::table('api_usage')->value('http_429'))->toBe(1);
    });

    it('drops reports first: the report bucket never waits', function () {
        $report = Call::get('https://api.mangadex.network/report');
        $this->rc->acquire($report, Priority::Prefetch, 0);
        $this->rc->acquire($report, Priority::Prefetch, 0);

        expect($this->rc->acquire($report, Priority::Prefetch, 0))->toBeInstanceOf(Busy::class);
    });
});
