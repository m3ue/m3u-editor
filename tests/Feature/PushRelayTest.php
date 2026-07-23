<?php

use App\Jobs\SendPushNotificationRelay;
use App\Models\Playlist;
use App\Models\PushDeviceToken;
use App\Models\User;
use App\Services\PushRelayService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    config(['services.push_relay.url' => 'https://push-relay.example.com']);
});

function mockPushRelaySettings(bool $enabled = true): void
{
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->push_relay_enabled = $enabled;
    app()->instance(GeneralSettings::class, $settings);
}

// ── PushRelayService ─────────────────────────────────────────────────────────────

it('isEnabled is false until relay is enabled and a URL is configured', function () {
    mockPushRelaySettings(enabled: false);
    expect(app(PushRelayService::class)->isEnabled())->toBeFalse();

    mockPushRelaySettings();
    expect(app(PushRelayService::class)->isEnabled())->toBeTrue();

    config(['services.push_relay.url' => null]);
    expect(app(PushRelayService::class)->isEnabled())->toBeFalse();
});

it('send posts to the configured relay with no auth header', function () {
    mockPushRelaySettings();
    Http::fake(['push-relay.example.com/*' => Http::response(['sent' => true])]);

    app(PushRelayService::class)->send('device-token', 'ios', 'Title', 'Body');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://push-relay.example.com/push'
            && ! $request->hasHeader('X-Relay-Secret')
            && $request['token'] === 'device-token'
            && $request['platform'] === 'ios'
            && $request['title'] === 'Title'
            && $request['body'] === 'Body';
    });
});

it('send throws when the relay responds with an error', function () {
    mockPushRelaySettings();
    Http::fake(['push-relay.example.com/*' => Http::response(['detail' => 'Bad token'], 502)]);

    expect(fn () => app(PushRelayService::class)->send('bad-token', 'ios', 'Title'))
        ->toThrow(RequestException::class);
});

it('uses PUSH_RELAY_URL from config, not a Settings-editable field', function () {
    config(['services.push_relay.url' => 'https://custom-relay.example.com']);
    mockPushRelaySettings();

    Http::fake(['custom-relay.example.com/*' => Http::response(['sent' => true])]);

    app(PushRelayService::class)->send('device-token', 'ios', 'Title');

    Http::assertSent(fn ($request) => $request->url() === 'https://custom-relay.example.com/push');
});

// ── SendPushNotificationRelay job ─────────────────────────────────────────────────

it('job does nothing when the relay is not enabled', function () {
    mockPushRelaySettings(enabled: false);
    Http::fake();

    PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create(['token' => 'tok-1']);

    (new SendPushNotificationRelay($this->playlist->getMorphClass(), $this->playlist->id, 'Title'))->handle(app(PushRelayService::class));

    Http::assertNothingSent();
});

it('job sends to every device token registered for the notifiable', function () {
    mockPushRelaySettings();
    Http::fake(['push-relay.example.com/*' => Http::response(['sent' => true])]);

    PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create(['token' => 'tok-1', 'platform' => 'ios']);
    PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create(['token' => 'tok-2', 'platform' => 'android']);

    (new SendPushNotificationRelay($this->playlist->getMorphClass(), $this->playlist->id, 'Title', 'Body'))->handle(app(PushRelayService::class));

    Http::assertSentCount(2);
});

it('job continues to remaining devices when one delivery fails', function () {
    mockPushRelaySettings();
    Http::fakeSequence()
        ->push(['detail' => 'Bad token'], 502)
        ->push(['sent' => true]);

    PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create(['token' => 'tok-1']);
    PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create(['token' => 'tok-2']);

    (new SendPushNotificationRelay($this->playlist->getMorphClass(), $this->playlist->id, 'Title'))->handle(app(PushRelayService::class));

    Http::assertSentCount(2);
});
