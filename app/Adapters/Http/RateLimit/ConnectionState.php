<?php

namespace App\Adapters\Http\RateLimit;

enum ConnectionState: string
{
    case Ok = 'ok';
    case Slowed = 'slowed';
    case Paused = 'paused';
}
