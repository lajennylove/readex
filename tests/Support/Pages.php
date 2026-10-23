<?php

namespace Tests\Support;

use App\Models\Chapter;

final class Pages
{
    /** A real image of this size, so getimagesize() measures it. */
    public static function image(int $width = 4, int $height = 6, string $type = 'jpeg'): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        $type === 'png' ? imagepng($image) : imagejpeg($image);

        return (string) ob_get_clean();
    }

    public static function chapter(string $id = 'c1', array $attributes = []): Chapter
    {
        return Chapter::query()->create([
            'id' => $id,
            'manga_id' => 'm1',
            'volume' => '2',
            'number' => '12',
            'title' => 'The Witch',
            'language' => 'es-la',
            'group_id' => 'g1',
            'group_name' => 'Blackhole Scan',
            'pages' => 3,
            ...$attributes,
        ]);
    }
}
