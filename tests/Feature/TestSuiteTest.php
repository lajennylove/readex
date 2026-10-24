<?php

const SUITE_MIN_MEMORY_BYTES = 512 * 1024 * 1024;

function parseMemoryLimitBytes(string $limit): int
{
    if ($limit === '-1') {
        return PHP_INT_MAX;
    }

    $limit = trim($limit);
    if ($limit === '') {
        return 0;
    }

    $last = strtolower($limit[strlen($limit) - 1]);
    if (in_array($last, ['g', 'm', 'k'], true)) {
        $value = (int) substr($limit, 0, -1);

        return match ($last) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
        };
    }

    return (int) $limit;
}

it('runs the test suite with at least 512M memory', function () {
    expect(parseMemoryLimitBytes(ini_get('memory_limit')))
        ->toBeGreaterThanOrEqual(SUITE_MIN_MEMORY_BYTES);
});
