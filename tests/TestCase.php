<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Set by a running `native:jump`, it makes System::runningInJump() true and rewrites image src values.
        putenv('JUMP_BRIDGE_PORT');
        unset($_ENV['JUMP_BRIDGE_PORT'], $_SERVER['JUMP_BRIDGE_PORT']);

        parent::setUp();

        Http::preventStrayRequests();
        // Unscripted bridge calls answer null here instead of reaching a Jump session on 127.0.0.1:3002.
        Native::fakeBridge();

    }
}
