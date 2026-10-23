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
}
