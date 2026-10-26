<?php

use App\Adapters\Http\RateLimit\Priority;
use App\Repositories\SettingsRepository;
use App\Sources\HttpSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Smoke test for the GitHub Pages demo content source (demo-source/). The static JSON
 * fixtures that site serves are loaded here as Http::fake responses and the built-in
 * HttpSource adapter is exercised end-to-end: catalog, title detail, chapter feed,
 * at-home server lookup, page URL composition. If this test ever breaks it means the
 * demo source has drifted from the adapter contract and App Review's handed URL will
 * stop working.
 *
 * This test does NOT hit the network. It just reads the files under demo-source/api/
 * and serves them to the client.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-26 00:00:00');
    Sleep::fake(syncWithCarbon: true);
    config(['readex.page_sizes.list' => 10]);
    config(['source.api_url' => 'https://demo.example/api']);
    config(['source.covers_url' => 'https://demo.example/covers']);
    app(SettingsRepository::class);
});

function demoSourceFixture(string $relativePath): string
{
    $base = dirname(__DIR__, 3).'/demo-source';
    $path = $base.'/'.ltrim($relativePath, '/');
    expect(is_file($path))->toBeTrue("missing demo-source fixture: {$relativePath}");

    return file_get_contents($path);
}

it('loads the demo catalog, drills into a title, reads its chapters, and resolves page URLs', function () {
    // Static GH Pages serves the same JSON regardless of query string.
    Http::fake([
        'demo.example/api/manga*' => function ($request) {
            $url = $request->url();
            if (str_contains($url, '/feed')) {
                // match /manga/{id}/feed
                preg_match('#/manga/([^/?]+)/feed#', $url, $m);

                return Http::response(demoSourceFixture("api/manga/{$m[1]}/feed.json"), 200);
            }
            if (preg_match('#/manga/([^/?]+)$|/manga/([^/?]+)\?#', $url, $m)) {
                $id = $m[1] ?: $m[2];
                if ($id !== '' && is_file(dirname(__DIR__, 3)."/demo-source/api/manga/{$id}.json")) {
                    return Http::response(demoSourceFixture("api/manga/{$id}.json"), 200);
                }
            }

            return Http::response(demoSourceFixture('api/manga/_catalog.json'), 200);
        },
        'demo.example/api/at-home/server/*' => function ($request) {
            preg_match('#/at-home/server/([^/?]+)#', $request->url(), $m);
            $body = demoSourceFixture("api/at-home/server/{$m[1]}.json");
            // The fixture carries `{BASE}` as a placeholder for the deployed GH Pages URL.
            $body = str_replace('{BASE}', 'https://demo.example', $body);

            return Http::response($body, 200);
        },
    ]);

    $source = app(HttpSource::class);

    // Catalog: the three demo titles land.
    $source->refreshSearch('', [], Priority::Interactive);
    $catalog = $source->search();
    $ids = $catalog->data->pluck('id')->all();
    expect($ids)->toContain('demo-cartographer-journal', 'demo-signal-across-water', 'demo-seasons-northern-shelf');

    // Chapter feed: a title's two chapters resolve, in order, under the demo scanlation group.
    $source->refreshChapterFeed('demo-cartographer-journal', 'en', Priority::Interactive);
    $chapters = $source->chapterFeed('demo-cartographer-journal', 'en')->data;
    expect($chapters->pluck('id')->all())->toBe(['ch-cart-1', 'ch-cart-2'])
        ->and($chapters->first()->group_name)->toBe('Demo Translators')
        ->and($chapters->first()->pages)->toBe(4);

    // Page URLs: at-home response gives a baseUrl + hash + file list; adapter composes to data/{hash}/{file}.
    $urls = $source->pageUrls('ch-cart-1', 'original', Priority::Interactive);
    expect($urls)->toHaveCount(4)
        ->and($urls[0])->toBe('https://demo.example/data/ch-cart-1/001.svg')
        ->and($urls[3])->toBe('https://demo.example/data/ch-cart-1/004.svg');

    // data-saver tier works too.
    $saverUrls = $source->pageUrls('ch-cart-1', 'data-saver', Priority::Interactive);
    expect($saverUrls[0])->toBe('https://demo.example/data-saver/ch-cart-1/001.svg');
});

it('ships at least 24 page SVGs and 3 cover SVGs on disk — enough for the reviewer to open a chapter', function () {
    $base = dirname(__DIR__, 3).'/demo-source';
    $pages = glob("{$base}/data/*/*.svg");
    $covers = glob("{$base}/covers/*/*.svg");

    expect(count($pages))->toBeGreaterThanOrEqual(24)
        ->and(count($covers))->toBe(3);
});
