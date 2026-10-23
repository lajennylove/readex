<?php

namespace Lajennylove\MdxStorage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Lajennylove\MdxStorage\MdxStorage
 */
class MdxStorage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Lajennylove\MdxStorage\MdxStorage::class;
    }
}
