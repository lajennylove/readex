<?php

use App\Adapters\Http\Client;
use App\Adapters\Http\Exceptions\ApiError;
use App\Adapters\Http\Exceptions\RequestDeferred;
use App\Adapters\Http\RateLimit\BusyReason;
use App\Adapters\Http\RateLimit\Priority;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_792_756_800));
    Sleep::fake(syncWithCarbon: true);
    config(['source.contact' => null, 'source.app_version' => '0.1.0']);
    $this->client = app(Client::class);
});

function usage(string $bucket): int
{
    return (int) DB::table('api_usage')->where('bucket', $bucket)->sum('requests');
}

describe('requests', function () {
    it('sends the app User-Agent and never a Via header', function () {
        config(['source.contact' => 'jenny@example.com']);
        Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'ok', 'data' => []])]);

        $this->client->get('/manga/tag');

        Http::assertSent(fn (Request $r) => $r->header('User-Agent') === ['ReaDex/0.1.0 (+jenny@example.com)']
            && ! $r->hasHeader('Via'));
    });

    it('leaves the contact out when none is set', function () {
        expect($this->client->userAgent())->toBe('ReaDex/0.1.0');
    });

    it('builds MangaDex query strings', function () {
        Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'ok', 'data' => []])]);

        $this->client->get('manga', ['limit' => 100, 'contentRating' => ['safe', 'suggestive'], 'order' => ['followedCount' => 'desc']]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mangadex.org/manga?limit=100'
            .'&contentRating%5B%5D=safe&contentRating%5B%5D=suggestive&order%5BfollowedCount%5D=desc');
    });

    it('decodes empty objects and empty arrays the same way', function () {
        Http::fake(['api.mangadex.org/*' => Http::response(
            '{"result":"ok","data":{"attributes":{"links":{},"altTitles":[],"description":[]}}}',
        )]);

        $data = $this->client->get('/manga/abc');

        expect($data['data']['attributes'])->toBe(['links' => [], 'altTitles' => [], 'description' => []]);
    });

    it('always goes through the request controller', function () {
        Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'ok'])]);

        $this->client->get('/manga');
        $this->client->get('/at-home/server/abc');

        expect(usage('global'))->toBe(1)
            ->and(usage('at-home'))->toBe(1)
            ->and(DB::table('rate_buckets')->where('key', 'at-home')->exists())->toBeTrue();
    });

    it('hands the rate-limit headers to the controller', function () {
        Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'ok'], 200, [
            'X-RateLimit-Limit' => '40',
            'X-RateLimit-Remaining' => '2',
            'X-RateLimit-Retry-After' => (string) (1_792_756_800 + 45),
        ])]);

        $this->client->get('/at-home/server/abc');

        expect(DB::table('rate_buckets')->where('key', 'at-home')->value('blocked_until'))
            ->toEqual(1_792_756_800 + 45);
    });
});

describe('errors', function () {
    it('throws RequestDeferred without sending when no slot frees up in time', function () {
        Http::fake();
        DB::table('rate_buckets')->insert([
            'key' => 'family:mangadex.org', 'capacity' => 0, 'refill_per_sec' => 0, 'tokens' => 0,
            'updated_at' => 1_792_756_800, 'blocked_until' => 1_792_756_800 + 60, 'strikes' => 0, 'probing' => false,
        ]);

        expect(fn () => $this->client->get('/manga', maxWait: 2))
            ->toThrow(fn (RequestDeferred $e) => expect($e->busy->reason)->toBe(BusyReason::Throttled));

        Http::assertNothingSent();
    });

    it('throws ApiError with the MangaDex detail and still records the response', function () {
        Http::fake(['api.mangadex.org/*' => Http::response(
            ['result' => 'error', 'errors' => [['status' => 404, 'title' => 'not_found', 'detail' => 'Manga not found']]],
            404,
        )]);

        expect(fn () => $this->client->get('/manga/missing'))
            ->toThrow(ApiError::class, 'HTTP 404: Manga not found');

        expect(usage('global'))->toBe(1);
    });

    it('records a connection failure and rethrows it', function () {
        Http::fake(['api.mangadex.org/*' => Http::failedConnection()]);

        expect(fn () => $this->client->get('/manga'))->toThrow(ConnectionException::class);

        expect(usage('global'))->toBe(1);
    });

    it('pauses everything after a 429 from the client', function () {
        Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'error'], 429, ['Retry-After' => '30'])]);

        expect(fn () => $this->client->get('/manga'))->toThrow(ApiError::class);
        expect(fn () => $this->client->download('https://uploads.mangadex.org/covers/a/b.jpg.256.jpg', maxWait: 0))
            ->toThrow(RequestDeferred::class);

        Http::assertSentCount(1);
    });
});

describe('sharing identical GETs', function () {
    it('sends one call when the same GET is asked twice within 5 seconds', function () {
        Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'ok', 'data' => [1]])]);

        $first = $this->client->get('/manga/abc/aggregate', ['translatedLanguage' => ['es-la']]);
        $second = $this->client->get('/manga/abc/aggregate', ['translatedLanguage' => ['es-la']], Priority::Prefetch);

        expect($second)->toBe($first);
        Http::assertSentCount(1);
    });

    it('waits for a call already in flight instead of sending a second one', function () {
        Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'ok', 'data' => ['a second call']])]);
        $url = 'https://api.mangadex.org/manga/abc/feed';
        $key = 'source:get:'.sha1($url);
        $store = Cache::store(config('source.share_store'));
        $inFlight = $store->lock($key.':lock', 30);
        $inFlight->acquire();

        Sleep::whenFakingSleep(function () use ($store, $key, $inFlight) {
            $store->put($key, '{"result":"ok","data":["from the other caller"]}', 5);
            $inFlight->forceRelease();
        });

        expect($this->client->get('/manga/abc/feed')['data'])->toBe(['from the other caller']);
        Http::assertNothingSent();
    });

    it('sends again once the shared answer is older than 5 seconds', function () {
        Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'ok'])]);

        $this->client->get('/manga/abc');
        Carbon::setTestNow(Carbon::now()->addSeconds(6));
        $this->client->get('/manga/abc');

        Http::assertSentCount(2);
    });

    it('does not share failed answers', function () {
        Http::fakeSequence('api.mangadex.org/*')
            ->push(['result' => 'error'], 503)
            ->push(['result' => 'ok', 'data' => [1]]);

        expect(fn () => $this->client->get('/manga/abc'))->toThrow(ApiError::class);
        expect($this->client->get('/manga/abc')['data'])->toBe([1]);
    });
});

describe('downloads and @Home reports', function () {
    it('reports @Home image results to the network', function () {
        Http::fake([
            'cdn.example.mangadex.network/*' => Http::response('JPEGBYTES', 200, ['X-Cache' => 'HIT']),
            'api.mangadex.network/report' => Http::response(['result' => 'ok']),
        ]);

        $bytes = $this->client->download('https://cdn.example.mangadex.network/data-saver/hash/1.jpg', Priority::Prefetch);

        expect($bytes)->toBe('JPEGBYTES');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mangadex.network/report'
            && $r->method() === 'POST'
            && $r['url'] === 'https://cdn.example.mangadex.network/data-saver/hash/1.jpg'
            && $r['success'] === true && $r['cached'] === true && $r['bytes'] === 9
            && is_int($r['duration']));
    });

    it('reports failed @Home images too', function () {
        Http::fake([
            'cdn.example.mangadex.network/*' => Http::response('', 502),
            'api.mangadex.network/report' => Http::response(['result' => 'ok']),
        ]);

        expect(fn () => $this->client->download('https://cdn.example.mangadex.network/data/hash/1.png'))
            ->toThrow(ApiError::class);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/report') && $r['success'] === false && $r['bytes'] === 0);
    });

    it('does not report mangadex.org hosts', function () {
        Http::fake(['uploads.mangadex.org/*' => Http::response('COVER')]);

        $this->client->download('https://uploads.mangadex.org/covers/a/b.jpg.256.jpg');

        Http::assertSentCount(1);
        expect(usage('covers'))->toBe(1);
    });

    it('drops the report instead of waiting when the report bucket is empty', function () {
        Http::fake([
            'cdn.example.mangadex.network/*' => Http::response('JPEG'),
            'api.mangadex.network/report' => Http::response(['result' => 'ok']),
        ]);

        foreach (range(1, 3) as $page) {
            $this->client->download("https://cdn.example.mangadex.network/data/hash/{$page}.jpg");
        }

        expect(usage('report'))->toBe(2);
        Http::assertSentCount(5);
    });

    it('never throws when the report itself fails', function () {
        Http::fake([
            'cdn.example.mangadex.network/*' => Http::response('JPEG'),
            'api.mangadex.network/report' => Http::failedConnection(),
        ]);

        expect($this->client->download('https://cdn.example.mangadex.network/data/hash/1.jpg'))->toBe('JPEG');
    });

    it('sends the report with 2 s / 3 s timeouts and the page with the usual 5 s / 20 s', function () {
        $timeouts = [];
        Http::fake(function (Request $request, array $options) use (&$timeouts) {
            $timeouts[parse_url($request->url(), PHP_URL_HOST)] = [$options['connect_timeout'], $options['timeout']];

            return str_contains($request->url(), '/report') ? Http::response(['result' => 'ok']) : Http::response('JPEG');
        });

        $this->client->download('https://cdn.example.mangadex.network/data/hash/1.jpg');

        expect($timeouts)->toBe([
            'cdn.example.mangadex.network' => [5, 20],
            'api.mangadex.network' => [2, 3],
        ]);
    });

    it('still returns the page when the report fails', function (Closure $report) {
        Http::fake([
            'cdn.example.mangadex.network/*' => Http::response('JPEG'),
            'api.mangadex.network/report' => $report,
        ]);

        expect($this->client->download('https://cdn.example.mangadex.network/data/hash/1.jpg'))->toBe('JPEG');
    })->with([
        'with a 522' => fn () => fn () => Http::response('', 522),
        'with a timeout' => fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 3001 milliseconds'),
    ]);

    it('sends no report for a minute after a failed one, then reports again', function (Closure $failure) {
        $reports = 0;
        Http::fake(function (Request $request) use (&$reports, $failure) {
            if (! str_contains($request->url(), '/report')) {
                return Http::response('JPEG');
            }

            return ++$reports === 1 ? $failure() : Http::response(['result' => 'ok']);
        });

        $reportTokens = fn () => DB::table('rate_buckets')->where('key', 'report')->value('tokens');
        $this->client->download('https://cdn.example.mangadex.network/data/hash/1.jpg');
        $tokens = $reportTokens();
        $this->client->download('https://cdn.example.mangadex.network/data/hash/2.jpg');

        expect($reportTokens())->toBe($tokens);

        Carbon::setTestNow(Carbon::now()->addSeconds(59));
        $this->client->download('https://cdn.example.mangadex.network/data/hash/3.jpg');

        expect($reports)->toBe(1)
            ->and(usage('report'))->toBe(1);

        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->client->download('https://cdn.example.mangadex.network/data/hash/4.jpg');

        expect($reports)->toBe(2)
            ->and(usage('report'))->toBe(2);
    })->with([
        'a 522' => fn () => fn () => Http::response('', 522),
        'a timeout' => fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 3001 milliseconds'),
    ]);

    it('keeps reporting while reports succeed', function () {
        Http::fake([
            'cdn.example.mangadex.network/*' => Http::response('JPEG'),
            'api.mangadex.network/report' => Http::response(['result' => 'ok']),
        ]);

        $this->client->download('https://cdn.example.mangadex.network/data/hash/1.jpg');
        $this->client->download('https://cdn.example.mangadex.network/data/hash/2.jpg');

        expect(usage('report'))->toBe(2);
        Http::assertSentCount(4);
    });
});
