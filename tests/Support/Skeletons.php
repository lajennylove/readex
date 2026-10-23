<?php

namespace Tests\Support;

use Closure;

/** Finds `native.partials.skeleton` blocks in a wire tree. */
final class Skeletons
{
    /** Every skeleton block, pulsing or faded, in tree order. */
    public static function all(array $node): array
    {
        $found = array_key_exists('animate-loop', $node['props'] ?? []) ? [$node] : [];

        foreach ($node['children'] ?? [] as $child) {
            $found = [...$found, ...self::all($child)];
        }

        return $found;
    }

    /** Still loading: the block pulses. */
    public static function pulsing(array $node): array
    {
        return array_values(array_filter(self::all($node), fn (array $n) => $n['props']['animate-loop'] === true));
    }

    /** Loaded: the block stopped pulsing and fades out over what it covered. */
    public static function faded(array $node): array
    {
        return array_values(array_filter(self::all($node), fn (array $n) => $n['props']['animate-loop'] === false
            && ($n['style']['opacity'] ?? null) == 0
            && ($n['props']['animate-duration'] ?? 0) > 0));
    }

    /**
     * EDGE's "key collision" log lines written while `$render` runs. A colliding native:key is
     * salted in document order, so ids stay unique but shift when anything before them changes.
     *
     * @return list<string>
     */
    public static function keyCollisions(Closure $render): array
    {
        $log = tempnam(sys_get_temp_dir(), 'edge-keys');
        $previous = ini_set('error_log', $log);

        try {
            $render();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $lines = array_values(preg_grep('/key collision/', file($log) ?: []));
        unlink($log);

        return $lines;
    }

    /** Placeholder tiles (native.partials.tile-skeleton): a 2:3 pulsing cover plus two pulsing lines. */
    public static function tiles(array $node): int
    {
        $children = $node['children'] ?? [];
        $count = ($node['type'] ?? null) === 'column'
            && ($node['layout']['width'] ?? null) == 112
            && count($children) === 3
            && count(self::pulsing($node)) === 3 ? 1 : 0;

        foreach ($children as $child) {
            $count += self::tiles($child);
        }

        return $count;
    }
}
