<?php

namespace App\Storage;

interface StorageBridge
{
    /** BCP-47 tag of the phone's first preferred language, or null when unknown. */
    public function preferredLanguage(): ?string;

    /** Absolute folder for purgeable data (iOS may delete it when the phone is full). */
    public function cachesPath(): string;

    public function excludeFromBackup(string $path): bool;
}
