<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReadingProgress extends Model
{
    protected $table = 'reading_progress';

    protected $primaryKey = 'chapter_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'page_count' => 'integer',
            'finished_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
