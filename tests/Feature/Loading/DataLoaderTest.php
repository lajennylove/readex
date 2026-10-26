<?php

use App\Adapters\Http\RateLimit\Priority;
use App\Loading\DataLoader;
use App\Loading\LoadProgress;
use App\Models\Tag;
use App\Refresh\RefreshStatus;
use App\Repositories\HomeRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TagRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Covers;
use Tests\Support\Loading;
use Tests\Support\SourceFixtures;

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    $this->caches = Covers::useTempCaches();
    app(SettingsRepository::class)->setLanguage('es-la');
    $this->progress = [];
    $this->record = function (string $step, string $state) {
        $this->progress[] = "{$step}:{$state}";
    };
});

function load(Priority $rest = Priority::Interactive): array
{
    return array_map(fn ($o) => $o->status->value, app(DataLoader::class)->run(test()->record, $rest));
}

function host(Request $request): string
{
    return parse_url($request->url(), PHP_URL_HOST).parse_url($request->url(), PHP_URL_PATH);
}

it('runs the first-run steps in order and fills every Home row', function () {
    Loading::fake();

    expect(load())->toBe(['tags' => 'ok', 'popular' => 'ok', 'latest' => 'ok', 'covers' => 'ok'])
        ->and($this->progress)->toBe([
            'tags:running', 'tags:ok',
            'popular:running', 'popular:ok',
            'latest:running', 'latest:ok',
            'covers:running', 'covers:ok',
        ]);

    $home = app(HomeRepository::class);
    expect(Tag::count())->toBe(77)
        ->and($home->popular()->isEmpty())->toBeFalse()
        ->and($home->latest()->isEmpty())->toBeFalse();
});

it('downloads the first twelve tiles\' covers, row by row in turns', function () {
    $covers = [];
    Loading::fake(covers: $covers);
    load();

    $home = app(HomeRepository::class);
    $popular = $home->popular()->data->values();
    $latest = $home->latest()->data->values()->map(fn ($u) => $u->manga);

    $expected = collect();
    for ($i = 0; $i < 6; $i++) {
        foreach ([$popular, $latest] as $row) {
            $expected->push($row[$i]->id);
        }
    }

    expect($covers)->toHaveCount(12)
        ->and(array_map(fn (string $url) => explode('/', $url)[4], $covers))->toBe($expected->unique()->values()->all())
        ->and($covers[0])->toEndWith('.256.jpg')
        ->and(glob($this->caches.'/covers/*/*.jpg'))->toHaveCount(12);
});

it('skips a broken cover and keeps going', function () {
    $covers = [];
    $firstCover = null;
    Loading::fake(function (Request $request) use (&$firstCover) {
        // Only cover URLs are candidates — pre-feature/0035 the Loading::fake
        // routed covers through uploads.mangadex.org; now covers are same-origin
        // under /covers/, so we match on the path rather than the host.
        if (! str_contains($request->url(), '/covers/')) {
            return null;
        }
        $firstCover ??= $request->url();

        return $request->url() === $firstCover ? Http::response('gone', 404) : null;
    }, $covers);

    expect(load()['covers'])->toBe('ok')
        ->and(glob($this->caches.'/covers/*/*.jpg'))->toHaveCount(11);
});

it('does not load tags again when Setup already got them', function () {
    SourceFixtures::fake('tags');
    app(TagRepository::class)->refresh();
    // Past the client's 5 s shared-GET cache, so a second tags call would reach Http.
    Carbon::setTestNow('2026-10-24 12:00:10');
    $asked = [];
    Loading::fake(function (Request $request) use (&$asked) {
        $asked[] = host($request);

        return null;
    });

    load();

    expect($asked)->not->toContain('api.mangadex.org/api/manga/tag')
        ->and($this->progress[1])->toBe('tags:ok');
});

it('keeps going when one row fails', function () {
    Loading::fake(fn (Request $request) => str_ends_with(host($request), '/chapter') ? Http::response(['result' => 'error'], 500) : null);

    expect(load())->toBe(['tags' => 'ok', 'popular' => 'ok', 'latest' => 'unavailable', 'covers' => 'ok'])
        ->and(app(HomeRepository::class)->popular()->isEmpty())->toBeFalse();
});

it('stops trying once the phone is offline (Review Focus 1)', function () {
    $asked = 0;
    Loading::fake(function () use (&$asked) {
        $asked++;

        throw new ConnectionException('cURL error 6');
    });

    expect(load())->toBe(array_fill_keys(array_keys(DataLoader::STEPS), RefreshStatus::Offline->value))
        ->and($asked)->toBe(1)
        ->and($this->progress)->toBe(['tags:running', 'tags:offline', 'popular:offline', 'latest:offline', 'covers:offline']);
});

it('loads the Home rows interactive and the rest at the given priority (spec §7.1)', function (Priority $rest, ?float $backgroundTokens) {
    Loading::fake();
    load($rest);

    // The clock is frozen, so one background call (tags) leaves capacity - 1 tokens.
    expect(DB::table('rate_buckets')->where('key', 'global:background')->value('tokens'))->toBe($backgroundTokens);
})->with([
    'Setup' => [Priority::Interactive, null],
    'Refresh MangaDex data' => [Priority::Bulk, 2.0],
]);

it('keeps step progress in the cache for the screen to poll', function () {
    LoadProgress::start('run-1');
    LoadProgress::record('run-1', 'tags', LoadProgress::RUNNING);
    LoadProgress::record('run-1', 'tags', 'ok');
    LoadProgress::record('run-1', 'popular', LoadProgress::RUNNING);

    expect(LoadProgress::read('run-1'))->toBe(['tags' => 'ok', 'popular' => 'running'])
        ->and(LoadProgress::read('other'))->toBe([]);

    LoadProgress::start('run-1');
    expect(LoadProgress::read('run-1'))->toBe([]);
});
