<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AtHome extends Model
{
    protected $table = 'at_home';

    protected $primaryKey = 'chapter_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data_files' => 'array',
            'data_saver_files' => 'array',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
