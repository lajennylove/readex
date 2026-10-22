<?php

use App\NativeComponents\Hello;
use Native\Mobile\Testing\Native;

it('renders the hello screen', function () {
    Native::test(Hello::class)->assertSee('MangaDex Reader');
});

it('is the start route', function () {
    Native::visit('/')->assertScreen(Hello::class);
});
