<?php

namespace App\Storage;

use Illuminate\Support\Facades\File;

final class CacheLink
{
    public static function ensure(StorageBridge $bridge): string
    {
        $target = $bridge->cachesPath();
        $link = rtrim(config('filesystems.disks.public.root'), '/').'/cache';

        if (is_link($link) && readlink($link) === $target) {
            return $target;
        }

        if (is_link($link) || is_file($link)) {
            unlink($link);
        } elseif (is_dir($link)) {
            File::deleteDirectory($link);
        }

        symlink($target, $link);

        return $target;
    }
}
