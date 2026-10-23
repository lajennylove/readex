<?php

namespace App\MangaDex\RateLimit;

enum ConnectionState: string
{
    case Ok = 'ok';
    case Slowed = 'slowed';
    case Paused = 'paused';
}
