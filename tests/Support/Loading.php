<?php

namespace Tests\Support;

use App\Sources\ActiveSource;
use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** The recorded first-run calls (es-la reader, 2026-10-24) plus cover thumbnails. */
final class Loading
{
    public const FIXTURES = ['tags', 'popular-new', 'latest-es-la', 'latest-es-la-manga'];

    /**
     * `$override` answers a request first (return null to pass it on), e.g. to make one
     * row hang or the phone offline.
     *
     * @param  list<string>  $covers  filled with each cover URL asked for
     */
    public static function fake(?Closure $override = null, array &$covers = []): void
    {
        Http::swap(new Factory(app('events')));
        $fixtures = SourceFixtures::stub(...self::FIXTURES);
        $jpeg = Covers::jpeg();

        Http::fake(function (Request $request) use ($override, $fixtures, $jpeg, &$covers) {
            if ($override !== null && ($answer = $override($request)) !== null) {
                return $answer;
            }

            if (str_starts_with($request->url(), rtrim(app(ActiveSource::class)->baseUrl(), '/').'/api/manga/_catalog.json')) {
                // Setup's source-URL health check (feature/0035): probes the catalog
                // endpoint, expects an envelope with result/response/data.
                return Http::response(['result' => 'ok', 'response' => 'collection', 'data' => []]);
            }

            // feature/0035 agnostic-source: covers now live same-origin as api,
            // under /covers/<manga>/<file>, so match the path not the host.
            if (str_starts_with($request->url(), rtrim(app(ActiveSource::class)->coversUrl(), '/').'/')) {
                $covers[] = $request->url();

                return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }

            return $fixtures($request);
        });
    }
}
