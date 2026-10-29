<?php

namespace App\NativeComponents;

use App\Adapters\Http\RateLimit\ConnectionState;
use App\Adapters\Http\RateLimit\ConnectionStatus;
use App\Adapters\Http\RateLimit\RequestController;
use App\Jobs\RefreshAllData;
use App\Language\Languages;
use App\Loading\DataLoader;
use App\Loading\LoadProgress;
use App\NativeComponents\Concerns\ChoosesContentRatings;
use App\NativeComponents\Concerns\ShowsResumeBar;
use App\NativeLayouts\HasResumeBar;
use App\Repositories\DataReset;
use App\Repositories\FactoryReset;
use App\Repositories\SettingsRepository;
use App\Sources\ActiveSource;
use App\Storage\DiskSpaceGuard;
use App\Storage\ImageCache;
use App\Storage\StorageBridge;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\Network;

/**
 * Settings tab (spec §5.7): reading language, content rating, image quality (§12.1),
 * "Refresh provider data" (§7.1), the provider connection row (§5.1a) and credits.
 * Storage: the image cache and "Clear image cache" (§11.1); iCloud comes with downloads.
 *
 * Plan-08 Task “screen audit” (Jenny 2026-10-24 18:29 EDT): section headings and field labels
 * are generic ("Provider data" / "Provider connection" / "Refresh provider data") so the screen
 * doesn't read as hardcoded to one named service. The attribution text below them
 * (`providerLabel()`/`providerUrl()`) stays generic too; the real source name and host show on
 * the sources screen, where the user manages what they've added.
 */
class Settings extends NativeComponent implements HasResumeBar
{
    use ChoosesContentRatings;
    use ShowsResumeBar;

    public const QUALITIES = [
        'data-saver' => 'Data saver (SD)',
        'original' => 'Original (HD)',
    ];

    public const REFRESH = 'Refresh';

    public const CLEAR = 'Clear';

    public const FACTORY_RESET = 'Delete everything';

    public const CHANGE_SOURCE = 'Continue';

    public const NEEDS_INTERNET = 'Needs internet';

    public string $language = 'en';

    public string $quality = 'data-saver';

    /** The refill whose steps are shown (the running one, or the last one this visit). */
    public ?string $run = null;

    /** @var array<string, string> step => LoadProgress state */
    public array $steps = [];

    /** Why the last tap on Refresh didn't run. */
    public ?string $refreshNote = null;

    /** Bytes of the image cache, counted when Settings opens and after Clear (not on every poll). */
    public int $cacheBytes = 0;

    /**
     * True when the device's free storage is at or below the configured minimum
     * (`readex.storage.min_free_bytes`), counted when Settings opens. Mirrors the same
     * DiskSpaceGuard check every write path uses, so what the user sees here matches what
     * actually blocks downloads and cache writes.
     */
    public bool $storageLow = false;

    public function mount(SettingsRepository $settings): void
    {
        $this->language = $settings->language();
        $this->ratings = $settings->contentRatings();
        $this->quality = $settings->imageQuality();
        $this->run = RefreshAllData::running();
        $this->steps = $this->run === null ? [] : LoadProgress::read($this->run);
        $this->cacheBytes = app(ImageCache::class)->usage();
        $this->storageLow = app(DiskSpaceGuard::class)->isLow();
    }

    public function navTitle(): string
    {
        return 'Settings';
    }

    public function chooseLanguage(string $name): void
    {
        $code = Languages::codeFor($name);

        if ($code !== null) {
            app(SettingsRepository::class)->setLanguage($code);
            $this->language = $code;
        }
    }

    protected function ratingsChanged(): void
    {
        app(SettingsRepository::class)->setContentRatings($this->ratings);
    }

    public function chooseQuality(string $label): void
    {
        $quality = array_search($label, self::QUALITIES, true);

        if ($quality !== false) {
            app(SettingsRepository::class)->setImageQuality($quality);
            $this->quality = $quality;
        }
    }

    public function askRefresh(): void
    {
        if (! $this->canRefresh()) {
            return;
        }

        Dialog::alert(
            'Refresh provider data',
            'Saved provider data is cleared and loaded again. Your Library, reading progress and downloads stay.',
            [['label' => 'Cancel', 'style' => 'cancel'], ['label' => self::REFRESH, 'style' => 'destructive']],
        )->buttonPressed(fn (ButtonPressed $pressed) => $pressed->label === self::REFRESH ? $this->refreshAllData() : null);
    }

    public function askClearCache(): void
    {
        Dialog::alert(
            'Clear image cache',
            'Covers and chapters saved for reading are deleted and loaded again when needed. Your Library, reading progress and downloads stay.',
            [['label' => 'Cancel', 'style' => 'cancel'], ['label' => self::CLEAR, 'style' => 'destructive']],
        )->buttonPressed(fn (ButtonPressed $pressed) => $pressed->label === self::CLEAR ? $this->clearCache() : null);
    }

    /**
     * Plan-08 Task 4 (Jenny 2026-10-24 18:29 EDT): "warn the user when tap it [that] this action
     * will delete everything and prompt if this is really intended". Deliberately more alarming
     * wording than Clear image cache / Refresh provider data — this is the one action in
     * Settings with no undo and no exception (every table, every file, every setting, the
     * sources themselves).
     */
    /**
     * Jenny 2026-10-26: entering a different source URL in Setup would read saved content whose
     * ids and shapes came from the previous source — stale covers, broken deep links, mismatched
     * tag ids. So this flushes the same tables factory reset does, then takes the user back to
     * Setup. EULA stays acknowledged (they already saw it); only Setup needs to run again.
     */
    public function askChangeSource(): void
    {
        Dialog::alert(
            'Change source URL?',
            'This removes your Library, reading progress, downloads and cached chapters so the new source starts clean. The EULA you already accepted stays accepted; the app returns to Setup to enter the new URL.',
            [['label' => 'Cancel', 'style' => 'cancel'], ['label' => self::CHANGE_SOURCE, 'style' => 'destructive']],
        )->buttonPressed(fn (ButtonPressed $pressed) => $pressed->label === self::CHANGE_SOURCE ? $this->changeSource() : null);
    }

    public function askFactoryReset(): void
    {
        Dialog::alert(
            'Delete everything?',
            "This permanently deletes your whole Library, reading progress, every download, every cached image, your content sources and all settings. This can't be undone. ReaDex will restart as if it were just installed.",
            [['label' => 'Cancel', 'style' => 'cancel'], ['label' => self::FACTORY_RESET, 'style' => 'destructive']],
        )->buttonPressed(fn (ButtonPressed $pressed) => $pressed->label === self::FACTORY_RESET ? $this->factoryReset() : null);
    }

    private function clearCache(): void
    {
        $cache = app(ImageCache::class);
        $cache->clear();
        $this->cacheBytes = $cache->usage();
    }

    /** Checked again after the dialog: nothing is cleared unless the refill can start (§7.1). */
    private function refreshAllData(): void
    {
        if (! $this->canRefresh()) {
            return;
        }

        app(DataReset::class)->clearRemoteData();

        $this->run = (string) Str::uuid();
        $this->steps = [];
        RefreshAllData::start($this->run);
    }

    /**
     * Everything gone; the next route check behaves exactly like a fresh install, because
     * `eula_acknowledged` and `setup_done` are gone along with every other row (Jenny: "take the
     * user to the splash window to add a new API source and select their settings").
     */
    private function factoryReset(): void
    {
        app(FactoryReset::class)->wipe();
        $this->replace('/');
    }

    private function changeSource(): void
    {
        app(FactoryReset::class)->wipe();
        app(SettingsRepository::class)->acknowledgeEula();
        $this->replace('/');
    }

    private function canRefresh(): bool
    {
        $this->refreshNote = null;

        if ($this->refilling() || $this->paused() !== null) {
            return false;
        }

        if (Network::status()?->connected === false) {
            $this->refreshNote = self::NEEDS_INTERNET;

            return false;
        }

        return true;
    }

    #[Poll(2000)]
    public function checkRefill(): void
    {
        if ($this->run !== null) {
            $this->steps = LoadProgress::read($this->run);
        }
    }

    public function refilling(): bool
    {
        return $this->run !== null && RefreshAllData::running() === $this->run;
    }

    public function openProvider(): void
    {
        Browser::inApp($this->providerUrl());
    }

    public function openSources(): void
    {
        $this->navigate('/settings/sources');
    }

    /** Generic label — the real provider name/host shows on the sources screen. */
    private function providerLabel(): string
    {
        return 'your provider';
    }

    private function providerUrl(): string
    {
        $active = app(ActiveSource::class);

        return $active->isConfigured() ? $active->baseUrl() : '';
    }

    /** "18:52" when the source has paused this phone, else null. */
    private function paused(?ConnectionStatus $status = null): ?string
    {
        $status ??= app(RequestController::class)->status();

        return $status->state === ConnectionState::Paused && $status->pausedUntil !== null
            ? $this->localTime($status->pausedUntil, 'H:i')
            : null;
    }

    /** @return array{state: string, usage: string} */
    public static function connectionLine(ConnectionStatus $status, ?string $pausedUntil): array
    {
        $state = match ($status->state) {
            ConnectionState::Ok => 'OK',
            ConnectionState::Slowed => 'Slowed down',
            ConnectionState::Paused => "Paused until {$pausedUntil}",
        };

        $minute = $status->requestsLastMinute === 1 ? '1 request' : number_format($status->requestsLastMinute).' requests';

        return [
            'state' => $state,
            'usage' => "{$minute} in the last minute · ".number_format($status->requestsLastHour).' in the last hour',
        ];
    }

    /** "Image cache 312 MB of 1 GB": decimal units like iOS Storage. */
    public static function storageLine(int $used, int $cap): string
    {
        return 'Image cache '.self::size($used).' of '.self::size($cap);
    }

    private static function size(int $bytes): string
    {
        $megabytes = (int) round($bytes / 1_000_000);

        return $megabytes < 1000 ? "{$megabytes} MB" : rtrim(rtrim(number_format($bytes / 1_000_000_000, 1), '0'), '.').' GB';
    }

    /** The app runs in UTC; times on screen follow the phone's clock. */
    private function localTime(CarbonInterface $time, string $format): string
    {
        return $time->toImmutable()->setTimezone(app(StorageBridge::class)->timeZone())->format($format);
    }

    public function render(): View
    {
        $status = app(RequestController::class)->status();
        $paused = $this->paused($status);
        $refreshedAt = app(SettingsRepository::class)->lastDataRefreshAt();
        $provider = $this->providerLabel();

        return view('native.settings', [
            'languageName' => Languages::name($this->language),
            'languageNames' => array_values(Languages::NAMES),
            'qualityName' => self::QUALITIES[$this->quality] ?? self::QUALITIES['data-saver'],
            'qualityNames' => array_values(self::QUALITIES),
            'connection' => self::connectionLine($status, $paused),
            'refilling' => $this->refilling(),
            'refreshLabel' => match (true) {
                $this->refilling() => 'Refreshing…',
                $paused !== null => "Paused until {$paused}",
                default => 'Refresh provider data',
            },
            'storage' => self::storageLine($this->cacheBytes, config('readex.cache.cap_bytes')),
            'storageLow' => $this->storageLow,
            'refreshBlocked' => $this->refilling() || $paused !== null,
            'lastRefreshed' => $refreshedAt === null ? 'Never refreshed' : 'Last refreshed '.$this->localTime($refreshedAt, 'M j, H:i'),
            'stepLabels' => DataLoader::STEPS,
            'version' => config('source.app_version'),
            'providerLabel' => $provider,
            'providerUrl' => $this->providerUrl(),
            'providerCredit' => 'The chapters are provided by '.(parse_url($this->providerUrl(), PHP_URL_HOST) ?: 'your source').' and the groups credited on each chapter.',
        ]);
    }
}
