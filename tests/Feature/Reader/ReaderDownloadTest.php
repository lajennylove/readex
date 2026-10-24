<?php

use App\Downloads\Downloads;
use App\Jobs\DownloadChapter;
use App\MangaDex\RateLimit\Priority;
use App\Models\Download;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use App\Repositories\LibraryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\Pages;
use Tests\Support\Stored;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 05:00:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    $this->caches = Covers::useTempCaches();
    Stored::useTempStorage();
    $this->async = AsyncTask::fake();
    Queue::fake();
    Http::fake(fn () => throw new ConnectionException('Airplane mode'));
});

afterEach(fn () => AsyncTask::clearFake());

/** @return list<array<string, mixed>> the nav bar actions of the last render */
function t7rActions(array $node): array
{
    $actions = ($node['type'] ?? null) === 'top_bar_action' ? [$node['props']] : [];

    return array_merge($actions, ...array_map(t7rActions(...), $node['children'] ?? []));
}

function t7rChapter(string $id, array $attributes = []): void
{
    Pages::chapter($id, ['manga_id' => 't7-rm', 'pages' => 3, ...$attributes]);
}

it('offers Download on a chapter not downloaded, and Stop download once it is queued (the reader\'s own job holds the unique lock)', function () {
    t7rChapter('t7-r1');

    $screen = Native::visit('/home/read/t7-r1')->assertNavTitle('Ch. 12 · 1 / 3');
    expect(t7rActions($screen->tree()))->toHaveCount(1)
        ->and(t7rActions($screen->tree())[0])->toMatchArray(['id' => 'download', 'icon' => 'arrow.down.circle']);

    Queue::assertPushed(DownloadChapter::class, fn (DownloadChapter $j) => $j->chapterId === 't7-r1' && $j->tier === 'cache');

    $screen->press('downloadChapter');

    Queue::assertPushed(DownloadChapter::class, 1);
    expect(t7rActions($screen->tree())[0])->toMatchArray(['id' => 'stop-download', 'icon' => 'stop.circle'])
        ->and(Download::query()->find('t7-r1')?->only(['batch', 'priority']))->toBe(['batch' => null, 'priority' => Priority::Prefetch->value]);

    $screen->press('stopDownload')->assertNativeNotCalled('Dialog.Alert');

    expect(Download::query()->find('t7-r1'))->toBeNull()
        ->and(t7rActions($screen->tree())[0])->toMatchArray(['id' => 'download']);
});

it('offers Download again on a stopped download', function () {
    t7rChapter('t7-r1');
    Stored::partial('t7-r1', 1, 3, tier: 'local');

    expect(t7rActions(Native::visit('/home/read/t7-r1')->tree())[0])->toMatchArray(['id' => 'download', 'icon' => 'arrow.down.circle']);
});

it('offers Remove download on a downloaded chapter, after a confirmation, and keeps the progress', function () {
    t7rChapter('t7-r1');
    Stored::chapter('t7-r1', 3, tier: 'local');
    app(LibraryRepository::class)->saveProgress('t7-r1', 1, 3);

    $screen = Native::visit('/home/read/t7-r1');
    expect(t7rActions($screen->tree())[0])->toMatchArray(['id' => 'remove-download', 'icon' => 'checkmark.circle.fill']);

    $screen->press('removeDownload')
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => $p['title'] === 'Remove download'
            && $p['message'] === 'It stays readable until the image cache needs the space. Your progress stays.'
            && $p['buttons'] === [['label' => 'Cancel', 'style' => 'cancel'], ['label' => 'Remove download', 'style' => 'destructive']])
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel']);
    expect(StoredChapter::query()->find('t7-r1')->tier)->toBe('local');

    $screen->press('removeDownload')->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Remove download']);

    expect(StoredChapter::query()->find('t7-r1')->tier)->toBe('cache')
        ->and(ReadingProgress::query()->find('t7-r1')->page)->toBe(1)
        ->and(t7rActions($screen->tree())[0])->toMatchArray(['id' => 'download']);
});

it('has no download action on a licensed chapter or a chapter that is not there', function () {
    t7rChapter('t7-r1', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/12', 'pages' => 0]);

    expect(t7rActions(Native::visit('/home/read/t7-r1')->tree()))->toBe([])
        ->and(t7rActions(Native::visit('/home/read/t7-gone')->assertNavTitle('Reader')->tree()))->toBe([]);
});

it('keeps hiding the bar on a tap with the action there', function () {
    t7rChapter('t7-r1');
    Stored::chapter('t7-r1', 3);

    Native::visit('/home/read/t7-r1')->assertNavBarVisible()->assertNavTitle('Ch. 12 · 1 / 3')
        ->press('toggleBar')->assertNavBarHidden()
        ->press('toggleBar')->assertNavBarVisible();
});

it('reads a downloaded chapter in Airplane mode: every page from the downloads, nothing asked for', function () {
    t7rChapter('t7-r1');
    Stored::chapter('t7-r1', 3, openedAt: '2026-10-23 20:00:00');
    app(Downloads::class)->download('t7-r1');

    $screen = Native::visit('/home/read/t7-r1')->call('windowChanged', 0, 4);

    expect(json_encode($screen->tree()))->toContain('storage\/downloads\/t7-r1\/003.jpg')
        ->not->toContain('Needs internet');
    Http::assertNothingSent();
    $this->async->assertNotDispatched();
});
