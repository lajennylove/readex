<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Statistic extends Model
{
    protected $table = 'statistics';

    protected $primaryKey = 'manga_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating_avg' => 'float',
            'rating_bayesian' => 'float',
            'follows' => 'integer',
            'distribution' => 'array',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
