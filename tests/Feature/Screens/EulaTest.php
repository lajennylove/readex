<?php

use App\NativeComponents\Eula;
use App\Repositories\SettingsRepository;
use Native\Mobile\Testing\Native;

it('shows the Terms heading and the responsibility-transfer clause', function () {
    Native::visit('/eula')
        ->assertScreen(Eula::class)
        ->assertSee('Terms for user-added sources')
        ->assertSee('User-Added Content Sources')
        ->assertSee('You are solely responsible for ensuring that you have the legal right')
        ->assertSee('I understand, continue');
});

it('persists acknowledgement and does not show again on the next cold start', function () {
    Native::test(Eula::class)->call('agree');

    expect(app(SettingsRepository::class)->eulaAcknowledged())->toBeTrue();

    Native::visit('/')->assertReplacedWith('/setup');
});

it('sends an acknowledged, not-yet-set-up phone on to Setup after agreeing', function () {
    Native::test(Eula::class)->call('agree')->assertReplacedWith('/setup');
});

it('sends an acknowledged, already-set-up phone straight to Home after agreeing', function () {
    app(SettingsRepository::class)->markSetupDone();

    Native::test(Eula::class)->call('agree')->assertReplacedWith('/home');
});
