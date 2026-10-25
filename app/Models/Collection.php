<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collection extends Model
{
    protected $table = 'collections';

    protected $primaryKey = 'manga_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'favorite' => 'boolean',
            'pinned' => 'boolean',
            'paused' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
