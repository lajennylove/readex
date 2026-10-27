<?php

namespace Tests\Support;

use App\Repositories\SettingsRepository;
use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** The recorded first-run calls (es-la reader, 2026-10-24) plus cover thumbnails. */
final class Loading
{
    public const FIXTURES = ['tags', 'seasonal-json', 'season-manga', 'popular-new', 'latest-es-la', 'latest-es-la-manga'];

    /**
     * `$override` answers a request first (return null to pass it on), e.g. to make one
     * row hang or the phone offline.
     *
     * @param  list<string>  $covers  filled with each cover URL asked for
     */
    public static function fake(?Closure $override = null, array &$covers = []): void
    {
        Http::swap(new Factory(app('events')));
        // Opt the "This season" row in so SourceFixtures still gets exercised; a dedicated
        // HomeRepositoryTest covers the "unset -> hidden" path.
        app(SettingsRepository::class)
            ->setSeasonalUrl('https://example.github.io/seasonal-list.json');
        $fixtures = SourceFixtures::stub(...self::FIXTURES);
        $jpeg = Covers::jpeg();

        Http::fake(function (Request $request) use ($override, $fixtures, $jpeg, &$covers) {
            if ($override !== null && ($answer = $override($request)) !== null) {
                return $answer;
            }

            if (rtrim($request->url(), '/') === rtrim(config('source.api_url'), '/')) {
                // Setup's source-URL health check (Plan-08 Task 3): a bare GET of the base URL.
                return Http::response('', 200);
            }

            $coversHost = (string) parse_url((string) config('source.covers_url'), PHP_URL_HOST);
            if ($coversHost !== '' && parse_url($request->url(), PHP_URL_HOST) === $coversHost) {
                $covers[] = $request->url();

                return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }

            return $fixtures($request);
        });
    }
}
