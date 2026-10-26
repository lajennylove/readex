<?php

use App\Adapters\Http\RateLimit\Priority;
use App\Models\AtHome;
use App\Repositories\AtHomeRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\SourceFixtures;

const AT_HOME_ONESHOT = '4e01bda4-0a04-4cdd-b3a9-edcafa7bbfd4';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    $this->atHome = app(AtHomeRepository::class);
});

/** An @Home answer with this base URL (fixture shape). */
function atHomeAnswer(string $baseUrl): array
{
    return ['result' => 'ok', 'baseUrl' => $baseUrl, 'chapter' => [
        'hash' => 'h1', 'data' => ['1-a.png', '2-b.png'], 'dataSaver' => ['1-a.jpg', '2-b.jpg'],
    ]];
}

describe('get', function () {
    it('is empty and stale until the server was looked up', function () {
        $result = $this->atHome->get(AT_HOME_ONESHOT);

        expect($result->data)->toBeNull()->and($result->isStale)->toBeTrue();
    });

    it('is fresh for 10 minutes, stale at exactly 10', function () {
        SourceFixtures::fake('at-home-oneshot');
        $this->atHome->refresh(AT_HOME_ONESHOT);

        Carbon::setTestNow(Carbon::now()->addSeconds(599));
        expect($this->atHome->get(AT_HOME_ONESHOT)->isStale)->toBeFalse();

        Carbon::setTestNow(Carbon::now()->addSecond());
        expect($this->atHome->get(AT_HOME_ONESHOT)->isStale)->toBeTrue();
    });
});

describe('refresh', function () {
    it('saves base URL, hash and both file lists in page order', function () {
        SourceFixtures::fake('at-home-oneshot');
        $body = SourceFixtures::body('at-home-oneshot');

        $server = $this->atHome->refresh(AT_HOME_ONESHOT, Priority::Interactive);

        expect($server)->toBeInstanceOf(AtHome::class)
            ->and($server->base_url)->toBe($body['baseUrl'])
            ->and($server->hash)->toBe($body['chapter']['hash'])
            ->and($server->data_files)->toBe($body['chapter']['data'])->toHaveCount(20)
            ->and($server->data_saver_files)->toBe($body['chapter']['dataSaver'])->toHaveCount(20)
            ->and(AtHome::query()->find(AT_HOME_ONESHOT)->fetched_at->toDateTimeString())->toBe('2026-10-24 12:00:00');
    });

    it('falls back to the parent of source.api_url when the response baseUrl is empty or non-absolute (single-endpoint sources like the App-Review demo)', function () {
        // The demo tree sits under <base>/demo-source/{api,data,covers,...}; stripping the
        // last /api segment of source.api_url points at <base>/demo-source so pageUrls()
        // composes <base>/demo-source/data/<hash>/<file>.
        config(['source.api_url' => 'https://pages.example.test/demo-source/api']);

        Http::fake([
            '*' => Http::sequence()
                ->push(atHomeAnswer(''))
                ->push(atHomeAnswer('/some/relative/path'))
                ->push(atHomeAnswer('   ')),
        ]);

        $this->atHome->refresh('c-empty');
        $this->atHome->refresh('c-relative');
        $this->atHome->refresh('c-whitespace');

        expect($this->atHome->get('c-empty')->data->base_url)->toBe('https://pages.example.test/demo-source')
            ->and($this->atHome->get('c-relative')->data->base_url)->toBe('https://pages.example.test/demo-source')
            ->and($this->atHome->get('c-whitespace')->data->base_url)->toBe('https://pages.example.test/demo-source');
    });

    it('keeps the response baseUrl when it is an absolute URL (normal @Home flow)', function () {
        Http::fake(['*' => Http::sequence()->push(atHomeAnswer('https://home.example.network'))]);

        $this->atHome->refresh('c-abs');

        expect($this->atHome->get('c-abs')->data->base_url)->toBe('https://home.example.network');
    });

    it('replaces an older lookup', function () {
        Http::fake(['*' => Http::sequence()->push(atHomeAnswer('https://old.mangadex.network'))->push(atHomeAnswer('https://new.mangadex.network'))]);

        $this->atHome->refresh('c1');
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->atHome->refresh('c1');

        expect(AtHome::query()->count())->toBe(1)
            ->and($this->atHome->get('c1')->data->base_url)->toBe('https://new.mangadex.network');
    });
});

describe('server', function () {
    it('uses a fresh lookup without a call', function () {
        Http::fake(['*' => Http::sequence()->push(atHomeAnswer('https://a.mangadex.network'))->push(atHomeAnswer('https://b.mangadex.network'))]);
        $this->atHome->refresh('c1');
        Carbon::setTestNow(Carbon::now()->addMinutes(9));

        expect($this->atHome->server('c1')->base_url)->toBe('https://a.mangadex.network');
        Http::assertSentCount(1);
    });

    it('looks the server up again once stale', function () {
        Http::fake(['*' => Http::sequence()->push(atHomeAnswer('https://a.mangadex.network'))->push(atHomeAnswer('https://b.mangadex.network'))]);
        $this->atHome->refresh('c1');
        Carbon::setTestNow(Carbon::now()->addMinutes(10));

        expect($this->atHome->server('c1', Priority::Prefetch)->base_url)->toBe('https://b.mangadex.network');
        Http::assertSentCount(2);
    });
});

describe('forget', function () {
    it('drops the lookup and the shared response, so the next lookup really asks MangaDex', function () {
        Http::fake(['*' => Http::sequence()->push(atHomeAnswer('https://dead.mangadex.network'))->push(atHomeAnswer('https://alive.mangadex.network'))]);
        $this->atHome->refresh('c1');

        // Within the client's 5 s shared-GET window: without forgetting it, the dead server would come back.
        $this->atHome->forget('c1');

        expect($this->atHome->get('c1')->data)->toBeNull()
            ->and($this->atHome->server('c1')->base_url)->toBe('https://alive.mangadex.network');
        Http::assertSentCount(2);
    });

    it('leaves other chapters alone', function () {
        Http::fake(['*' => Http::response(atHomeAnswer('https://a.mangadex.network'))]);
        $this->atHome->refresh('c1');
        $this->atHome->refresh('c2');

        $this->atHome->forget('c1');

        expect($this->atHome->get('c2')->data)->not->toBeNull();
    });
});

describe('pageUrls', function () {
    it('builds data-saver and original URLs in page order', function () {
        SourceFixtures::fake('at-home-oneshot');
        $body = SourceFixtures::body('at-home-oneshot');
        $server = $this->atHome->refresh(AT_HOME_ONESHOT);
        $hash = $body['chapter']['hash'];

        $saver = AtHomeRepository::pageUrls($server, 'data-saver');
        $original = AtHomeRepository::pageUrls($server, 'original');

        expect($saver)->toHaveCount(20)
            ->and($saver[0])->toBe("{$body['baseUrl']}/data-saver/{$hash}/{$body['chapter']['dataSaver'][0]}")
            ->and($original[19])->toBe("{$body['baseUrl']}/data/{$hash}/{$body['chapter']['data'][19]}");
    });

    it('rejects an unknown quality', function () {
        $server = new AtHome(['base_url' => 'https://a', 'hash' => 'h', 'data_files' => [], 'data_saver_files' => []]);

        AtHomeRepository::pageUrls($server, 'hd');
    })->throws(InvalidArgumentException::class);
});

it('asks the at-home endpoint for the chapter', function () {
    Http::fake(['*' => Http::response(atHomeAnswer('https://a.mangadex.network'))]);

    $this->atHome->refresh('c1');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mangadex.org/at-home/server/c1');
});
