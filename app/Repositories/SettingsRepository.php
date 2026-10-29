<?php

namespace App\Repositories;

use App\Language\LanguageChain;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SettingsRepository
{
    public function language(): string
    {
        return $this->read('language') ?? config('readex.defaults.language');
    }

    public function chain(): LanguageChain
    {
        return new LanguageChain($this->language());
    }

    /** Choice 5: Latest updates is filtered by language, so it goes stale; other lists are re-sorted at read time. */
    public function setLanguage(string $code): void
    {
        $code = strtolower(trim($code));

        if (preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/', $code) !== 1) {
            throw new InvalidArgumentException("Not a valid source language code [{$code}].");
        }

        if ($code === $this->language()) {
            return;
        }

        DB::transaction(function () use ($code) {
            $this->write('language', $code);
            DB::table('manga_lists')->where('list_key', 'home.latest')->update(['fetched_at' => null]);
        });
    }

    /** @return list<string> */
    public function contentRatings(): array
    {
        return $this->read('content_ratings') ?? config('readex.defaults.content_ratings');
    }

    /**
     * Choice 5 / spec §8: a different set clears every saved list (Home included); downloads and Library stay.
     *
     * @param  list<string>  $ratings
     */
    public function setContentRatings(array $ratings): void
    {
        $known = config('readex.content_ratings');

        if ($ratings === [] || array_diff($ratings, $known) !== []) {
            throw new InvalidArgumentException('Content ratings must be a non-empty subset of '.implode(', ', $known).'.');
        }

        $ratings = array_values(array_intersect($known, $ratings));

        if ($ratings === $this->contentRatings()) {
            return;
        }

        DB::transaction(function () use ($ratings) {
            $this->write('content_ratings', $ratings);
            DB::table('manga_lists')->delete();
        });
    }

    public function imageQuality(): string
    {
        return $this->read('image_quality') ?? config('readex.defaults.image_quality');
    }

    public function setImageQuality(string $quality): void
    {
        if (! in_array($quality, config('readex.image_qualities'), true)) {
            throw new InvalidArgumentException("Unknown image quality [{$quality}].");
        }

        $this->write('image_quality', $quality);
    }

    public function setupDone(): bool
    {
        return $this->read('setup_done') === true;
    }

    public function markSetupDone(): void
    {
        $this->write('setup_done', true);
    }

    /** Plan-08 Task 5 (D45 §5.6/§6a): the draft Terms/EULA screen is shown once, before Setup. */
    public function eulaAcknowledged(): bool
    {
        return $this->read('eula_acknowledged') === true;
    }

    public function acknowledgeEula(): void
    {
        $this->write('eula_acknowledged', true);
    }

    public function lastDataRefreshAt(): ?CarbonImmutable
    {
        $value = $this->read('last_data_refresh_at');

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    public function markDataRefreshed(): void
    {
        $this->write('last_data_refresh_at', Carbon::now()->toIso8601String());
    }

    /**
     * Search keeps its title and tag until the field is emptied or the chip deselected.
     *
     * @return array{query: string, tag_id: ?string}
     */
    public function lastSearch(): array
    {
        $saved = $this->read('last_search') ?? [];

        return ['query' => (string) ($saved['query'] ?? ''), 'tag_id' => $saved['tag_id'] ?? null];
    }

    public function rememberSearch(string $query, ?string $tagId): void
    {
        $this->write('last_search', ['query' => $query, 'tag_id' => $tagId]);
    }

    /**
     * The one content source URL the user configured at first-run Setup.
     * ReaDex is agnostic — no hardcoded source — so every HTTP call reads
     * this value (via App\Sources\ActiveSource). null when the user hasn't
     * finished Setup yet, or after a "Delete everything" factory reset.
     */
    public function sourceUrl(): ?string
    {
        $url = $this->read('source_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function setSourceUrl(string $url): void
    {
        $this->write('source_url', rtrim(trim($url), '/'));
    }

    public function clearSourceUrl(): void
    {
        Setting::query()->whereKey('source_url')->delete();
    }

    /**
     * Optional covers CDN override. Many real APIs serve their JSON on one host
     * and their cover images on another; when the user needs that, they paste
     * the second host here. null falls back to the auto-composed sibling of
     * the source URL (see App\Sources\ActiveSource::coversUrl).
     */
    public function coversUrl(): ?string
    {
        $url = $this->read('covers_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function setCoversUrl(string $url): void
    {
        $this->write('covers_url', rtrim(trim($url), '/'));
    }

    public function clearCoversUrl(): void
    {
        Setting::query()->whereKey('covers_url')->delete();
    }

    /**
     * Optional curated-list URL (the "This season" row on Home). The source
     * doesn't have to serve one — when this is null the row is hidden.
     */
    public function seasonalUrl(): ?string
    {
        $url = $this->read('seasonal_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function setSeasonalUrl(string $url): void
    {
        $this->write('seasonal_url', rtrim(trim($url), '/'));
    }

    public function clearSeasonalUrl(): void
    {
        Setting::query()->whereKey('seasonal_url')->delete();
    }

    /**
     * Whether the configured source serves static .json files (demo-shape) or a dynamic
     * REST-style API (the shape the HTTP adapter was modelled on). Written by
     * SourceMetaProber after each source URL change so the Client stops / starts appending
     * .json automatically. null until the first probe has run — then config's env default
     * is used as the fallback.
     */
    public function sourceIsStaticJson(): ?bool
    {
        $value = $this->read('source_is_static_json');

        return is_bool($value) ? $value : null;
    }

    public function setSourceIsStaticJson(bool $value): void
    {
        $this->write('source_is_static_json', $value);
    }

    public function clearSourceIsStaticJson(): void
    {
        Setting::query()->whereKey('source_is_static_json')->delete();
    }

    private function read(string $key): mixed
    {
        return Setting::find($key)?->value;
    }

    private function write(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
