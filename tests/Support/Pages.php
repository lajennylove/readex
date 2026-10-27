<?php

namespace Tests\Support;

use App\Models\Chapter;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class Pages
{
    /** An /at-home/server answer: `$count` pages `p1…` on this server (`.png` original, `.jpg` data saver). */
    public static function atHome(string $baseUrl, int $count = 3): array
    {
        return ['result' => 'ok', 'baseUrl' => $baseUrl, 'chapter' => [
            'hash' => 'h1',
            'data' => array_map(fn (int $i) => "p{$i}.png", range(1, $count)),
            'dataSaver' => array_map(fn (int $i) => "p{$i}.jpg", range(1, $count)),
        ]];
    }

    /**
     * Fake the source for page downloads. Each /at-home/server lookup answers the next base URL
     * (the last one repeats); images go to `$image(Request)` (default: a real JPEG); image
     * reports answer ok. Anything else throws. Hosts come from config so test fixtures
     * stay upstream-neutral.
     */
    public static function fake(array $servers = ['https://a.source.network.test'], int $count = 3, ?Closure $image = null): void
    {
        $image ??= fn () => Http::response(self::image());
        $lookups = 0;
        $apiPrefix = rtrim((string) config('source.api_url'), '/').'/at-home/server/';
        $reportHost = (string) parse_url((string) config('source.report_url'), PHP_URL_HOST);
        $imageFamily = '.source.network.test';

        Http::fake(function (Request $request) use ($servers, $count, $image, $apiPrefix, $reportHost, $imageFamily, &$lookups) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);

            if (str_starts_with($request->url(), $apiPrefix)) {
                return Http::response(self::atHome($servers[min($lookups++, count($servers) - 1)], $count));
            }

            if ($reportHost !== '' && $host === $reportHost) {
                return Http::response(['result' => 'ok']);
            }

            if (str_ends_with($host, $imageFamily)) {
                return $image($request);
            }

            throw new \RuntimeException("Unexpected request {$request->url()}");
        });
    }

    /** @return list<string> image requests in order as "host/path" (no lookups, no reports) */
    public static function requested(): array
    {
        $reportHost = (string) parse_url((string) config('source.report_url'), PHP_URL_HOST);

        return Http::recorded()
            ->map(fn (array $pair) => parse_url($pair[0]->url(), PHP_URL_HOST).parse_url($pair[0]->url(), PHP_URL_PATH))
            ->filter(fn (string $url) => ! str_starts_with($url, 'api.') && ($reportHost === '' || ! str_starts_with($url, $reportHost)))
            ->values()->all();
    }

    public static function lookups(): int
    {
        return Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), '/at-home/server/'))->count();
    }

    /** A real image of this size, so getimagesize() measures it. */
    public static function image(int $width = 4, int $height = 6, string $type = 'jpeg'): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        $type === 'png' ? imagepng($image) : imagejpeg($image);

        return (string) ob_get_clean();
    }

    public static function chapter(string $id = 'c1', array $attributes = []): Chapter
    {
        return Chapter::query()->create([
            'id' => $id,
            'manga_id' => 'm1',
            'volume' => '2',
            'number' => '12',
            'title' => 'The Witch',
            'language' => 'es-la',
            'group_id' => 'g1',
            'group_name' => 'Blackhole Scan',
            'pages' => 3,
            ...$attributes,
        ]);
    }
}
