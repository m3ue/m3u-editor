<?php

use App\Models\ArrIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
});

it('can be created via factory', function () {
    $integration = ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
    ]);

    expect($integration)->toBeInstanceOf(ArrIntegration::class);
    expect($integration->user_id)->toBe($this->user->id);
    expect($integration->enabled)->toBeTrue();
    expect($integration->guest_enabled)->toBeFalse();
});

it('casts api_key to encrypted', function () {
    $integration = ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
        'api_key' => 'plain-secret-key',
    ]);

    // Decrypt attribute returns the original
    expect($integration->api_key)->toBe('plain-secret-key');

    // Underlying DB column is encrypted (not the plaintext)
    $raw = DB::table('arr_integrations')->where('id', $integration->id)->value('api_key');
    expect($raw)->not->toBe('plain-secret-key');
    expect(strlen($raw))->toBeGreaterThan(20);
});

it('hides api_key from array/serialization', function () {
    $integration = ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $array = $integration->toArray();
    expect($array)->not->toHaveKey('api_key');
});

it('has a user relationship', function () {
    $integration = ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
    ]);

    expect($integration->user)->toBeInstanceOf(User::class);
    expect($integration->user->id)->toBe($this->user->id);
});

it('has isSonarr and isRadarr helpers', function () {
    $sonarr = ArrIntegration::factory()->sonarr()->create(['user_id' => $this->user->id]);
    $radarr = ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id]);

    expect($sonarr->isSonarr())->toBeTrue();
    expect($sonarr->isRadarr())->toBeFalse();
    expect($radarr->isRadarr())->toBeTrue();
    expect($radarr->isSonarr())->toBeFalse();
});

it('strips trailing slash from base_url', function () {
    $integration = ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
        'url' => 'http://192.168.1.42:8989/',
    ]);

    expect($integration->base_url)->toBe('http://192.168.1.42:8989');
});

it('scopeEnabled and scopeGuestEnabled filter correctly', function () {
    ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'guest_enabled' => true,
    ]);
    ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
        'enabled' => false,
        'guest_enabled' => true,
    ]);
    ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'guest_enabled' => false,
    ]);

    expect(ArrIntegration::enabled()->count())->toBe(2);
    expect(ArrIntegration::guestEnabled()->count())->toBe(2);
    expect(ArrIntegration::enabled()->guestEnabled()->count())->toBe(1);
});
