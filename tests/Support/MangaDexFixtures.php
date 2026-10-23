<?php

namespace Tests\Support;

use App\MangaDex\Query;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Recorded MangaDex responses (tests/Fixtures/mangadex). No test reaches the real API. */
final class MangaDexFixtures
{
    public static function path(string $name): string
    {
        return dirname(__DIR__)."/Fixtures/mangadex/{$name}.json";
    }

    /** The body decoded the way Client::get() decodes it. */
    public static function body(string $name): array
    {
        return json_decode(file_get_contents(self::path($name)), true, flags: JSON_THROW_ON_ERROR)['body'];
    }

    /**
     * Serve these fixtures to matching GETs (same path, same query in any order). Covers aren't
     * recorded: they answer 404, so a screen's cover fetch skips them. Any other request throws,
     * so a repository asking for something unrecorded fails loudly.
     */
    public static function fake(string ...$names): void
    {
        Http::fake(self::stub(...$names));
    }

    /** The fake's handler, for tests that answer some URLs themselves and pass the rest on. */
    public static function stub(string ...$names): Closure
    {
        $responses = [];

        foreach ($names as $name) {
            $raw = file_get_contents(self::path($name));
            $meta = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $body = json_decode($raw, false, flags: JSON_THROW_ON_ERROR)->body;
            $base = str_starts_with($meta['path'], 'https://') ? $meta['path'] : rtrim(config('mangadex.api_url'), '/').$meta['path'];
            $url = $base.($meta['query'] ? '?'.Query::build($meta['query']) : '');

            $responses[self::key($url)] = [json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $meta['status'], $meta['headers']];
        }

        return function (Request $request) use ($responses) {
            if (str_starts_with($request->url(), rtrim(config('mangadex.covers_url'), '/').'/')) {
                return Http::response('', 404);
            }

            $found = $responses[self::key($request->url())] ?? null;

            if ($found === null || $request->method() !== 'GET') {
                throw new RuntimeException("No MangaDex fixture for {$request->method()} {$request->url()}");
            }

            return Http::response(...$found);
        };
    }

    /** Query pairs sorted, except `order[...]`: its sequence is the sort priority. */
    private static function key(string $url): string
    {
        $parts = parse_url($url);
        $pairs = isset($parts['query']) && $parts['query'] !== '' ? array_map(rawurldecode(...), explode('&', $parts['query'])) : [];
        $order = array_values(array_filter($pairs, fn (string $pair) => str_starts_with($pair, 'order[')));
        $other = array_diff($pairs, $order);
        sort($other);

        return $parts['host'].($parts['path'] ?? '/').'?'.implode('&', $other).'#'.implode('&', $order);
    }
}
