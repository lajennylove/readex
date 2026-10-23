<?php

namespace Tests\Support;

use App\Models\Manga;
use App\NativeComponents\Concerns\ShowsTiles;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** A row of tiles for the series in `$ids`, with optional badge / caption per id. */
final class TileScreen extends NativeComponent
{
    use ShowsTiles;

    /** @var list<string> */
    public static array $ids = [];

    /** @var array<string, string> */
    public static array $badges = [];

    /** @var array<string, string> */
    public static array $captions = [];

    public static function reset(array $ids = [], array $badges = [], array $captions = []): void
    {
        self::$ids = $ids;
        self::$badges = $badges;
        self::$captions = $captions;
    }

    public function mount(): void
    {
        $this->queueCovers($this->mangas());
    }

    public function render(): View
    {
        ViewFactory::addNamespace('tests', __DIR__.'/views');

        return view('tests::tile-screen', [
            'mangas' => $this->mangas(),
            'badges' => self::$badges,
            'captions' => self::$captions,
        ]);
    }

    /** @return list<Manga> */
    private function mangas(): array
    {
        $found = Manga::query()->whereIn('id', self::$ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (string $id) => $found->get($id), self::$ids)));
    }
}
