<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoredChapter extends Model
{
    protected $table = 'stored_chapters';

    protected $primaryKey = 'chapter_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pages_done' => 'integer',
            'page_count' => 'integer',
            'bytes' => 'integer',
            'last_opened_at' => 'immutable_datetime',
        ];
    }
}
