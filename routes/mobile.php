<?php

use App\NativeComponents\Hello;
use Illuminate\Support\Facades\Route;

Route::native('/', Hello::class);
