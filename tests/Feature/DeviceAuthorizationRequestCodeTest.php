<?php

use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mockDevicePairingSettings(bool $pairingEnabled = true, bool $outputEnabled = true): void
{
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->device_pairing_enabled = $pairingEnabled;
    $settings->app_output_enabled = $outputEnabled;
    app()->instance(GeneralSettings::class, $settings);
}

it('issues a device_code/user_code pair with expiry and verification uri', function () {
    $response = $this->postJson('/api/device/code');

    $response->assertOk()->assertJsonStructure([
        'device_code',
        'user_code',
        'verification_uri',
        'interval',
        'expires_in',
    ]);

    $body = $response->json();

    expect($body['user_code'])->toMatch('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/');
    expect(strlen($body['device_code']))->toBe(64);
    expect($body['verification_uri'])->toContain('/pdt?code='.$body['user_code']);
    expect($body['interval'])->toBe(5);
    expect($body['expires_in'])->toBeGreaterThan(0);

    $this->assertDatabaseHas('device_authorizations', [
        'device_code' => $body['device_code'],
        'user_code' => $body['user_code'],
        'status' => 'pending',
    ]);
});

it('applies throttle middleware to the device code request route', function () {
    $route = app('router')->getRoutes()->getByName('device.code');

    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain('throttle:20,1');
});

it('applies throttle middleware to the device token poll route', function () {
    $route = app('router')->getRoutes()->getByName('device.token');

    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain('throttle:60,1');
});

it('returns 404 when device pairing is disabled', function () {
    mockDevicePairingSettings(pairingEnabled: false);

    $this->postJson('/api/device/code')->assertNotFound();
});

it('returns 404 when enhanced output is disabled, even if device pairing is enabled', function () {
    mockDevicePairingSettings(pairingEnabled: true, outputEnabled: false);

    $this->postJson('/api/device/code')->assertNotFound();
});

it('the /pdt vanity URL redirects to the pairing tab and forwards the code', function () {
    $this->get('/pdt?code=XKQP-9F3T')
        ->assertRedirect()
        ->assertRedirectContains('tab=pairing')
        ->assertRedirectContains('code=XKQP-9F3T');
});

it('the /pdt vanity URL redirects without a code param when none is given', function () {
    $this->get('/pdt')
        ->assertRedirect()
        ->assertRedirectContains('tab=pairing');

    expect($this->get('/pdt')->headers->get('Location'))->not->toContain('code=');
});
