<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Manga extends Model
{
    protected $table = 'manga';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'alt_titles' => 'array',
            'description' => 'array',
            'year' => 'integer',
            'available_languages' => 'array',
            'tag_ids' => 'array',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
