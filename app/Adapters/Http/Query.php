<?php

namespace App\Adapters\Http;

/** MangaDex query strings: lists as key[]=v, maps as key[sub]=v, booleans as true/false. */
final class Query
{
    public static function build(array $params): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $pairs[] = rawurlencode($key).'[]='.rawurlencode(self::scalar($item));
                }
            } elseif (is_array($value)) {
                foreach ($value as $sub => $item) {
                    $pairs[] = rawurlencode($key).'['.rawurlencode((string) $sub).']='.rawurlencode(self::scalar($item));
                }
            } elseif ($value !== null) {
                $pairs[] = rawurlencode($key).'='.rawurlencode(self::scalar($value));
            }
        }

        return implode('&', $pairs);
    }

    private static function scalar(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
