<?php

namespace App\NativeComponents;

use App\Language\IosLanguageMap;
use App\Language\Languages;
use App\Loading\DataLoader;
use App\Loading\LoadProgress;
use App\Loading\LoadTask;
use App\NativeComponents\Concerns\ChoosesContentRatings;
use App\Refresh\Refresh;
use App\Refresh\RefreshStatus;
use App\Refresh\RefreshTask;
use App\Repositories\SettingsRepository;
use App\Repositories\TagRepository;
use App\Storage\StorageBridge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Network;

/**
 * First run (spec §9): language + content rating, then the DataLoader steps with progress.
 * Home opens when every step is done, or after `setup_cutoff_seconds` once a Home row has
 * loaded; empty rows then refresh on Home. `setup_done` is only set when Home opens.
 */
class Setup extends NativeComponent
{
    use ChoosesContentRatings;

    public const SETTINGS = 'settings';

    public const LOADING = 'loading';

    public const OFFLINE = 'offline';

    /** Home is opening; a late task callback must not open it again. */
    public const DONE = 'done';

    public string $phase = self::SETTINGS;

    public string $language = 'en';

    public ?string $run = null;

    public int $startedAt = 0;

    /** @var array<string, string> step => LoadProgress state */
    public array $steps = [];

    public function mount(SettingsRepository $settings, StorageBridge $bridge): void
    {
        $this->language = IosLanguageMap::toMangaDex($bridge->preferredLanguage());
        $this->ratings = $settings->contentRatings();

        // Tags load while the reader picks; DataLoader skips them if they landed.
        RefreshTask::dispatch(Refresh::of([TagRepository::class, 'refresh']));
    }

    public function chooseLanguage(string $name): void
    {
        $this->language = Languages::codeFor($name) ?? $this->language;
    }

    /** Continue, and Retry after "First launch needs internet". */
    public function continueSetup(): void
    {
        $settings = app(SettingsRepository::class);

        if (Network::status()?->connected === false) {
            $this->phase = self::OFFLINE;

            return;
        }

        $settings->setLanguage($this->language);
        $settings->setContentRatings($this->ratings);

        $run = $this->run = (string) Str::uuid();
        LoadProgress::start($run);
        $this->phase = self::LOADING;
        $this->steps = [];
        $this->startedAt = Carbon::now()->getTimestampMs();

        LoadTask::dispatch($run)
            ->timeout(120)
            ->finished(fn (array $outcomes) => $this->loadingFinished(array_map(fn (array $o) => $o['status'], $outcomes)))
            ->failed(fn () => $this->loadingFinished(LoadProgress::read($run)));
    }

    #[Poll(1000)]
    public function checkLoading(): void
    {
        if ($this->phase !== self::LOADING) {
            return;
        }

        $this->steps = LoadProgress::read($this->run);
        $finished = array_filter($this->steps, fn (string $state) => $state !== LoadProgress::RUNNING);

        if (count($finished) === count(DataLoader::STEPS)) {
            $this->loadingFinished($this->steps);

            return;
        }

        $waited = Carbon::now()->getTimestampMs() - $this->startedAt;
        $rowLoaded = array_intersect_key($finished, array_flip(DataLoader::HOME_ROWS));

        if ($waited >= config('readex.setup_cutoff_seconds') * 1000 && in_array(RefreshStatus::Ok->value, $rowLoaded, true)) {
            $this->enterHome();
        }
    }

    /** @param  array<string, string>  $states */
    private function loadingFinished(array $states): void
    {
        if ($this->phase !== self::LOADING) {
            return;
        }

        $this->steps = $states;
        $rows = array_map(fn (string $row) => $states[$row] ?? RefreshStatus::Failed->value, DataLoader::HOME_ROWS);

        if (array_unique($rows) === [RefreshStatus::Offline->value]) {
            $this->phase = self::OFFLINE;

            return;
        }

        $this->enterHome();
    }

    private function enterHome(): void
    {
        $this->phase = self::DONE;
        app(SettingsRepository::class)->markSetupDone();
        $this->replace('/home');
    }

    /** Text after a step's label in the progress list. */
    public static function stepLine(?string $state): string
    {
        return match ($state) {
            null => 'Waiting',
            LoadProgress::RUNNING => 'Loading…',
            RefreshStatus::Ok->value => 'Done',
            RefreshStatus::Offline->value => 'Offline',
            RefreshStatus::Busy->value, RefreshStatus::Limited->value => 'Later',
            default => 'Retries on Home',
        };
    }

    public function render(): View
    {
        return view('native.setup', [
            'languageName' => Languages::name($this->language),
            'languageNames' => array_values(Languages::NAMES),
        ]);
    }
}
