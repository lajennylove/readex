<?php

namespace App\Storage;

final class LocalStorageBridge implements StorageBridge
{
    public function __construct(private readonly string $root) {}

    public function preferredLanguage(): ?string
    {
        return null;
    }

    public function cachesPath(): string
    {
        $path = $this->root.'/caches';
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }

    public function excludeFromBackup(string $path): bool
    {
        return false;
    }

    /** The Mac's zone (Jump), read from /etc/localtime because PHP's default is the app's UTC. */
    public function timeZone(): string
    {
        $target = @readlink('/etc/localtime');

        if (is_string($target) && preg_match('#zoneinfo/(.+)$#', $target, $m)
            && in_array($m[1], timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            return $m[1];
        }

        return 'UTC';
    }
}
