<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ViewedSeries extends Model
{
    protected $table = 'viewed_series';

    protected $primaryKey = 'manga_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_viewed_at' => 'immutable_datetime',
            'last_viewed_at' => 'immutable_datetime',
        ];
    }
}
