<?php

namespace App\Storage;

final class NativeStorageBridge implements StorageBridge
{
    public function __construct(private readonly LocalStorageBridge $fallback) {}

    public function preferredLanguage(): ?string
    {
        return $this->call('MdxStorage.PreferredLanguage')['language'] ?? null;
    }

    public function cachesPath(): string
    {
        return $this->call('MdxStorage.CachesPath')['path'] ?? $this->fallback->cachesPath();
    }

    public function excludeFromBackup(string $path): bool
    {
        return (bool) ($this->call('MdxStorage.ExcludeFromBackup', ['path' => $path])['excluded'] ?? false);
    }

    public function timeZone(): string
    {
        $zone = $this->call('MdxStorage.TimeZone')['zone'] ?? null;

        return is_string($zone) && in_array($zone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
            ? $zone
            : $this->fallback->timeZone();
    }

    /** @return array<string, mixed>|null */
    private function call(string $method, array $params = []): ?array
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $raw = nativephp_call($method, json_encode((object) $params));
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded) || ($decoded['status'] ?? null) === 'error') {
            return null;
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
    }
}
