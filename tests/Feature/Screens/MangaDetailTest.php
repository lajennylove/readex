<?php

use App\Language\Languages;
use App\Models\Chapter;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\Statistic;
use App\NativeComponents\MangaDetail;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\MangaDexFixtures;

const DETAIL_RTW = 'aafb046c-9de4-4c3d-990c-6d75b079c0b9';
const DETAIL_NOVA = '8a45c930-32d1-423e-bffd-9e8d71312134';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    Covers::useTempCaches();
    app(SettingsRepository::class)->setLanguage('es-la');
    $this->async = AsyncTask::fake();
    Queue::fake();
    $this->asked = [];
    $fixtures = MangaDexFixtures::stub('manga-rtw', 'manga-nova', 'statistics-rtw');
    Http::fake(function (Request $request) use ($fixtures) {
        $this->asked[] = rawurldecode($request->url());

        return $fixtures($request);
    });
});

afterEach(fn () => AsyncTask::clearFake());

/** Every text in the last render, in screen order. */
function detailTexts(array $node): array
{
    $texts = ($node['type'] ?? null) === 'text' ? [$node['props']['text']] : [];

    foreach ($node['children'] ?? [] as $child) {
        $texts = [...$texts, ...detailTexts($child)];
    }

    return $texts;
}

/** @return list<string> labels of every button */
function detailButtons(array $node): array
{
    $labels = ($node['type'] ?? null) === 'button' ? [$node['props']['label'] ?? ''] : [];

    foreach ($node['children'] ?? [] as $child) {
        $labels = [...$labels, ...detailButtons($child)];
    }

    return $labels;
}

function detailChapter(string $id, string $mangaId, ?string $number, int $pages = 20): Chapter
{
    return Chapter::query()->create([
        'id' => $id, 'manga_id' => $mangaId, 'volume' => null, 'number' => $number, 'title' => null,
        'language' => 'es-la', 'group_id' => null, 'group_name' => null, 'pages' => $pages,
        'external_url' => null, 'readable_at' => Carbon::now(), 'fetched_at' => Carbon::now(),
    ]);
}

it('loads a never-opened series and shows its header, stats, languages and description', function () {
    $screen = Native::visit('/search/manga/'.DETAIL_RTW)->assertScreen(MangaDetail::class);
    $texts = detailTexts($screen->tree());
    $chain = app(SettingsRepository::class)->chain();
    $languages = array_map(Languages::name(...), ['es-la', 'es', 'en', 'fr', 'id', 'pl', 'pt-br', 'ru', 'tr', 'uk']);

    expect(collect($this->asked)->filter(fn (string $url) => str_contains($url, '/manga/'.DETAIL_RTW.'?'))->values()->all())
        ->toHaveCount(1)
        ->and(collect($this->asked)->filter(fn (string $url) => str_contains($url, '/statistics/manga/'.DETAIL_RTW))->all())->toHaveCount(1)
        ->and($texts)->toContain('Story: Er Mu (二目)')
        ->toContain('Art: Yuewen Manhua (阅文漫画), Dr. Woodman')
        ->toContain('Completed · 2018')
        ->toContain('★ 8.32 · 40,308 follows')
        ->toContain(implode(' · ', $languages))
        ->and(collect($texts)->contains(fn (string $t) => str_starts_with($t, 'A male engineer transmigrated')))->toBeTrue();

    $manga = Manga::query()->find(DETAIL_RTW);
    $screen->assertNavTitle($chain->title($manga->title, $manga->alt_titles));
});

it('says "Story & art" when the author also drew it', function () {
    $texts = detailTexts(Native::visit('/home/manga/'.DETAIL_NOVA)->tree());

    expect($texts)->toContain('Story & art: Clevercool')
        ->and(collect($texts)->contains(fn (string $t) => str_starts_with($t, 'Art:')))->toBeFalse()
        ->and($texts)->toContain('Ongoing · 2026');
});

it('asks for the detail of a series only saved by a list call (no author yet)', function () {
    Covers::manga(DETAIL_RTW, title: 'From a list');
    Manga::query()->whereKey(DETAIL_RTW)->update(['fetched_at' => Carbon::now()]);

    $texts = detailTexts(Native::visit('/search/manga/'.DETAIL_RTW)->tree());

    expect(collect($this->asked)->filter(fn (string $url) => str_contains($url, '/manga/'.DETAIL_RTW.'?'))->all())->toHaveCount(1)
        ->and($texts)->toContain('Story: Er Mu (二目)');
});

it('shows nothing but the status line when offline with nothing saved', function () {
    Http::swap(new Factory(app('events')));
    Http::fake(fn () => throw new ConnectionException('offline'));

    $screen = Native::visit('/search/manga/'.DETAIL_RTW);

    expect(detailTexts($screen->tree()))->toContain($screen->instance()->statusLine())
        ->and($screen->instance()->statusLine())->not->toBeNull()
        ->and(detailButtons($screen->tree()))->not->toContain('Favorite');
});

it('adds the series to the Library as a favorite and takes it back off', function () {
    $screen = Native::visit('/search/manga/'.DETAIL_RTW);
    expect(detailButtons($screen->tree()))->toContain('Favorite');

    $screen->tap('Favorite');
    expect(LibraryEntry::query()->find(DETAIL_RTW)?->favorite)->toBeTrue()
        ->and(detailButtons($screen->tree()))->toContain('Favorited')->not->toContain('Favorite');

    $screen->tap('Favorited');
    expect(LibraryEntry::query()->find(DETAIL_RTW)?->favorite)->toBeFalse()
        ->and(detailButtons($screen->tree()))->toContain('Favorite');
});

it('has no Continue button for a series never read', function () {
    expect(collect(detailButtons(Native::visit('/search/manga/'.DETAIL_RTW)->tree()))->filter(fn (string $l) => str_starts_with($l, 'Continue'))->all())->toBe([]);
});

it('continues where the reader left off, on the saved page shown from 1', function () {
    Native::visit('/library/manga/'.DETAIL_RTW);
    detailChapter('c-12', DETAIL_RTW, '12');
    detailChapter('c-13', DETAIL_RTW, '13');
    app(LibraryRepository::class)->saveProgress('c-12', 4, 20);

    $screen = Native::visit('/library/manga/'.DETAIL_RTW);
    expect(detailButtons($screen->tree()))->toContain('Continue · Ch. 12, page 5');

    $screen->tap('Continue · Ch. 12, page 5')
        ->assertNativeCalled('Dialog.Toast', fn (array $p) => $p['message'] === 'Reader comes in the next phase');
});

it('continues with the next chapter once the last one read is finished', function () {
    Native::visit('/library/manga/'.DETAIL_RTW);
    detailChapter('c-12', DETAIL_RTW, '12');
    detailChapter('c-13', DETAIL_RTW, '13');
    app(LibraryRepository::class)->saveProgress('c-12', 19, 20);

    expect(detailButtons(Native::visit('/library/manga/'.DETAIL_RTW)->tree()))->toContain('Continue · Ch. 13');
});

it('shows the Bayesian rating MangaDex shows, and says so when nobody rated yet', function () {
    expect(MangaDetail::stats(new Statistic(['rating_avg' => 9.4, 'rating_bayesian' => 7.05, 'follows' => 1200])))->toBe('★ 7.05 · 1,200 follows')
        ->and(MangaDetail::stats(new Statistic(['rating_avg' => null, 'rating_bayesian' => null, 'follows' => 1])))->toBe('No ratings yet · 1 follow')
        ->and(MangaDetail::stats(null))->toBeNull();
});
