<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MangaListEntry extends Model
{
    protected $table = 'manga_lists';

    protected $primaryKey = null;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'batch' => 'integer',
            'position' => 'integer',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
