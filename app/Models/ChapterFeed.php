<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChapterFeed extends Model
{
    protected $table = 'chapter_feeds';

    protected $primaryKey = null;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
