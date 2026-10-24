<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceConfig extends Model
{
    protected $table = 'sources';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'blocked' => 'boolean',
            'config' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
