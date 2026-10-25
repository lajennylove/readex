<?php

declare(strict_types=1);

namespace App\Storage;

use App\Storage\Exceptions\DiskSpaceLow;

/**
 * Defense-in-depth guard (incident 2026-10-25): iOS gives an app no direct view of total
 * device-free space, but `disk_free_space()` against the app's own storage container path is
 * available and is a fair proxy — the container volume is the device volume. Every call site
 * that writes a nontrivial file (CoverStore::download(), PageStore::put()/promote()/demote())
 * checks free space first and skips the write rather than silently proceeding on a device that
 * is almost out of room, so a bug in any one writer can no longer compound an already-bad
 * situation.
 */
final class DiskSpaceGuard
{
    public function __construct(private readonly StorageBridge $bridge) {}

    /** Bytes free on the volume backing the app's storage container, or null if unreadable. */
    public function freeBytes(): ?int
    {
        $free = @disk_free_space($this->path());

        return $free === false ? null : (int) $free;
    }

    /** True when free space is known and at or below the configured minimum. */
    public function isLow(): bool
    {
        $free = $this->freeBytes();

        return $free !== null && $free <= $this->minFreeBytes();
    }

    /**
     * Call before writing a nontrivial file. Does nothing when there is room.
     *
     * @throws DiskSpaceLow free space is at or below `readex.storage.min_free_bytes`
     */
    public function ensure(): void
    {
        $free = $this->freeBytes();

        if ($free === null) {
            return;
        }

        $min = $this->minFreeBytes();

        if ($free <= $min) {
            throw new DiskSpaceLow($free, $min);
        }
    }

    private function minFreeBytes(): int
    {
        return (int) config('readex.storage.min_free_bytes');
    }

    private function path(): string
    {
        return $this->bridge->cachesPath();
    }
}
