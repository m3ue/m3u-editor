<?php

use App\Events\PlaylistCreated;
use App\Events\TvNotificationEvent;
use App\Jobs\SendPushNotificationRelay;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\PushDeviceToken;
use App\Models\TvNotification;
use App\Models\TvNotificationRead;
use App\Models\User;
use App\Notifications\Notification;
use App\Services\PushRelayService;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Event::fake([PlaylistCreated::class]);
    $this->withoutMiddleware(ThrottleRequestsWithRedis::class);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->auth1 = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'guest1',
        'password' => 'pass1',
        'enabled' => true,
    ]);
    $this->auth1->assignTo($this->playlist);

    $this->auth2 = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'guest2',
        'password' => 'pass2',
        'enabled' => true,
    ]);
    $this->auth2->assignTo($this->playlist);
});

// -- tvBroadcast stores playlist_auth_id -----------------------------------------------

it('tvBroadcast stores playlist_auth_id when PlaylistAuth is provided', function () {
    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    Notification::make()
        ->title('Targeted notification')
        ->danger()
        ->tvBroadcast($this->playlist, 'general', false, $this->auth1);

    $record = TvNotification::first();
    expect($record->playlist_auth_id)->toBe($this->auth1->id);
});

it('tvBroadcast stores null playlist_auth_id when no PlaylistAuth is provided', function () {
    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    Notification::make()
        ->title('Broadcast to all')
        ->success()
        ->tvBroadcast($this->playlist, 'general');

    $record = TvNotification::first();
    expect($record->playlist_auth_id)->toBeNull();
});

// -- Notifications endpoint scoping ---------------------------------------------------

it('playlist auth guest sees both null-scoped and own-scoped notifications', function () {
    TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Global notification',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => null,
    ]);

    TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Auth1 targeted',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => $this->auth1->id,
    ]);

    TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Auth2 targeted',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => $this->auth2->id,
    ]);

    $this->getJson(route('tv.notifications', ['username' => 'guest1', 'password' => 'pass1']))
        ->assertOk()
        ->assertJsonCount(2, 'notifications');
});

it('playlist auth guest does not see other auth targeted notifications', function () {
    TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Auth2 targeted only',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => $this->auth2->id,
    ]);

    $this->getJson(route('tv.notifications', ['username' => 'guest1', 'password' => 'pass1']))
        ->assertOk()
        ->assertJsonCount(0, 'notifications');
});

it('owner admin auth sees global notifications but not requester-targeted notifications', function () {
    $admin = User::factory()->admin()->create();
    $this->playlist->update(['user_id' => $admin->id]);

    TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Auth1 targeted',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => $this->auth1->id,
    ]);

    TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Global',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => null,
    ]);

    $this->getJson(route('tv.notifications', ['username' => $admin->name, 'password' => $this->playlist->uuid]))
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.title', 'Global');

    $this->postJson(route('tv.notifications.read', [
        'username' => $admin->name,
        'password' => $this->playlist->uuid,
        'id' => TvNotification::where('title', 'Auth1 targeted')->value('id'),
    ]))->assertNotFound();

    $this->postJson(route('tv.notifications.read', [
        'username' => $admin->name,
        'password' => $this->playlist->uuid,
        'id' => TvNotification::where('title', 'Global')->value('id'),
    ]))->assertOk();
});

it('owner non-admin auth sees and marks global notifications but not requester-targeted notifications', function () {
    $targeted = TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Requester targeted',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => $this->auth1->id,
    ]);
    $global = TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Global owner notification',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => null,
    ]);
    $ownerCredentials = [
        'username' => $this->user->name,
        'password' => $this->playlist->uuid,
    ];

    $this->getJson(route('tv.notifications', $ownerCredentials))
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.id', $global->id);

    $this->postJson(route('tv.notifications.read', [...$ownerCredentials, 'id' => $targeted->id]))
        ->assertNotFound();
    $this->postJson(route('tv.notifications.read', [...$ownerCredentials, 'id' => $global->id]))
        ->assertOk();

    expect($targeted->fresh()->read_at)->toBeNull()
        ->and($global->fresh()->read_at)->not->toBeNull();
});

// -- Reverb credential isolation ------------------------------------------------------

it('delivers a global notification to every credential transport on only its playlist', function () {
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $otherAuth = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'other-guest',
        'password' => 'other-pass',
        'enabled' => true,
    ]);
    $otherAuth->assignTo($otherPlaylist);
    $disabledAuth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => false,
    ]);
    $disabledAuth->assignTo($this->playlist);
    $expiredAuth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'expires_at' => now()->subMinute(),
    ]);
    $expiredAuth->assignTo($this->playlist);

    PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth1->id,
        'token' => 'device-auth1-global',
        'platform' => 'android',
    ]);
    PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth2->id,
        'token' => 'device-auth2-global',
        'platform' => 'ios',
    ]);
    PushDeviceToken::factory()->create([
        'notifiable_type' => $otherPlaylist->getMorphClass(),
        'notifiable_id' => $otherPlaylist->id,
        'playlist_auth_id' => $otherAuth->id,
        'token' => 'device-other-global',
        'platform' => 'android',
    ]);
    foreach ([$disabledAuth, $expiredAuth] as $ineligibleAuth) {
        PushDeviceToken::factory()->create([
            'notifiable_type' => $this->playlist->getMorphClass(),
            'notifiable_id' => $this->playlist->id,
            'playlist_auth_id' => $ineligibleAuth->id,
            'token' => "device-ineligible-{$ineligibleAuth->id}",
            'platform' => 'android',
        ]);
    }

    $auth1Channel = $this->getJson(route('tv.notifications', [
        'username' => 'guest1',
        'password' => 'pass1',
    ]))->assertOk()->json('reverb.channel');
    $auth2Channel = $this->getJson(route('tv.notifications', [
        'username' => 'guest2',
        'password' => 'pass2',
    ]))->assertOk()->json('reverb.channel');
    $otherChannel = $this->getJson(route('tv.notifications', [
        'username' => 'other-guest',
        'password' => 'other-pass',
    ]))->assertOk()->json('reverb.channel');

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    Notification::make()
        ->title('Global transport parity')
        ->danger()
        ->tvBroadcast($this->playlist, 'general');

    $notification = TvNotification::sole();
    $event = null;
    $relayJob = null;

    Event::assertDispatched(TvNotificationEvent::class, function (TvNotificationEvent $dispatched) use (&$event): bool {
        $event = $dispatched;

        return true;
    });
    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $dispatched) use (&$relayJob): bool {
        $relayJob = $dispatched;

        return true;
    });

    $channels = collect($event->broadcastOn())->pluck('name');

    expect($notification->playlist_auth_id)->toBeNull()
        ->and($event->id)->toBe($notification->id)
        ->and($relayJob->notificationUuid)->toBe($notification->id)
        ->and($channels)->toContain($auth1Channel, $auth2Channel)
        ->and($channels)->not->toContain(
            $otherChannel,
            "private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}.{$disabledAuth->id}",
            "private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}.{$expiredAuth->id}",
        );

    foreach ([
        ['username' => 'guest1', 'password' => 'pass1'],
        ['username' => 'guest2', 'password' => 'pass2'],
    ] as $credentials) {
        $this->getJson(route('tv.notifications', $credentials))
            ->assertOk()
            ->assertJsonPath('notifications.0.id', $notification->id);
    }

    $this->getJson(route('tv.notifications', [
        'username' => 'other-guest',
        'password' => 'other-pass',
    ]))
        ->assertOk()
        ->assertJsonCount(0, 'notifications');

    $deliveredTokens = [];
    $relay = Mockery::mock(PushRelayService::class);
    $relay->shouldReceive('isEnabled')->once()->andReturnTrue();
    $relay->shouldReceive('send')
        ->twice()
        ->andReturnUsing(function (string $token, string $platform, string $title, ?string $body, ?array $data) use (&$deliveredTokens): void {
            $deliveredTokens[$token] = data_get($data, 'notification_id');
        });

    $relayJob->handle($relay);

    expect($deliveredTokens)->toBe([
        'device-auth1-global' => $notification->id,
        'device-auth2-global' => $notification->id,
    ]);
});

it('announces a distinct private Reverb channel for each credential on one playlist', function () {
    $auth1Channel = $this->getJson(route('tv.notifications', [
        'username' => 'guest1',
        'password' => 'pass1',
    ]))->assertOk()->json('reverb.channel');

    $auth2Channel = $this->getJson(route('tv.notifications', [
        'username' => 'guest2',
        'password' => 'pass2',
    ]))->assertOk()->json('reverb.channel');

    expect($auth1Channel)->not->toBe($auth2Channel);
});

it('authorizes only the private Reverb channel announced for the current credential', function () {
    $auth1Channel = $this->getJson(route('tv.notifications', [
        'username' => 'guest1',
        'password' => 'pass1',
    ]))->assertOk()->json('reverb.channel');

    $auth2Channel = $this->getJson(route('tv.notifications', [
        'username' => 'guest2',
        'password' => 'pass2',
    ]))->assertOk()->json('reverb.channel');

    $url = route('tv.broadcasting.auth', [
        'username' => 'guest1',
        'password' => 'pass1',
    ]);

    $this->postJson($url, [
        'socket_id' => '123456.78910',
        'channel_name' => $auth1Channel,
    ])->assertOk();

    $this->postJson($url, [
        'socket_id' => '123456.78910',
        'channel_name' => $auth2Channel,
    ])->assertForbidden();
});

it('broadcasts a credential-targeted notification only on that credential private channel', function () {
    $auth1Channel = $this->getJson(route('tv.notifications', [
        'username' => 'guest1',
        'password' => 'pass1',
    ]))->assertOk()->json('reverb.channel');

    $auth2Channel = $this->getJson(route('tv.notifications', [
        'username' => 'guest2',
        'password' => 'pass2',
    ]))->assertOk()->json('reverb.channel');

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    Notification::make()
        ->title('Auth1 private request')
        ->success()
        ->tvBroadcast($this->playlist, 'requests', false, $this->auth1);

    Event::assertDispatched(TvNotificationEvent::class, function (TvNotificationEvent $event) use ($auth1Channel, $auth2Channel): bool {
        $channels = collect($event->broadcastOn())->pluck('name');

        return $channels->contains($auth1Channel)
            && ! $channels->contains($auth2Channel);
    });
});

// -- Push relay isolation -------------------------------------------------------------

it('push relay with playlistAuthId only reaches devices for that auth', function () {
    PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth1->id,
        'token' => 'device-auth1',
    ]);

    PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth2->id,
        'token' => 'device-auth2',
    ]);

    PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => null,
        'token' => 'device-global',
    ]);

    $relay = Mockery::mock(PushRelayService::class);
    $relay->shouldReceive('isEnabled')->once()->andReturnTrue();
    $relay->shouldReceive('send')
        ->once()
        ->with('device-auth1', Mockery::type('string'), 'Targeted', null, null);

    (new SendPushNotificationRelay(
        notifiableType: $this->playlist->getMorphClass(),
        notifiableId: $this->playlist->id,
        title: 'Targeted',
        playlistAuthId: $this->auth1->id,
    ))->handle($relay);
});

it('push relay with null playlistAuthId reaches every entitled and legacy global device', function () {
    PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth1->id,
        'token' => 'device-auth1',
    ]);

    PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => null,
        'token' => 'device-global',
    ]);

    $deliveredTokens = [];
    $relay = Mockery::mock(PushRelayService::class);
    $relay->shouldReceive('isEnabled')->once()->andReturnTrue();
    $relay->shouldReceive('send')
        ->twice()
        ->andReturnUsing(function (string $token) use (&$deliveredTokens): void {
            $deliveredTokens[] = $token;
        });

    (new SendPushNotificationRelay(
        notifiableType: $this->playlist->getMorphClass(),
        notifiableId: $this->playlist->id,
        title: 'Global',
    ))->handle($relay);

    sort($deliveredTokens);

    expect($deliveredTokens)->toBe(['device-auth1', 'device-global']);
});

it('does not widen an admin-only notification to credential devices', function () {
    PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth1->id,
        'token' => 'device-auth1-admin-only',
    ]);
    PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => null,
        'token' => 'device-admin',
    ]);

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    Notification::make()
        ->title('Admin only')
        ->danger()
        ->tvBroadcast($this->playlist, 'general', true);

    $relayJob = null;
    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $dispatched) use (&$relayJob): bool {
        $relayJob = $dispatched;

        return true;
    });

    $deliveredTokens = [];
    $relay = Mockery::mock(PushRelayService::class);
    $relay->shouldReceive('isEnabled')->once()->andReturnTrue();
    $relay->shouldReceive('send')
        ->andReturnUsing(function (string $token) use (&$deliveredTokens): void {
            $deliveredTokens[] = $token;
        });

    $relayJob->handle($relay);

    expect($deliveredTokens)->toBe(['device-admin']);
});

it('tvBroadcast with PlaylistAuth dispatches relay with playlistAuthId', function () {
    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    Notification::make()
        ->title('Auth-scoped notification')
        ->danger()
        ->tvBroadcast($this->playlist, 'general', false, $this->auth1);

    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $job) {
        return $job->playlistAuthId === $this->auth1->id;
    });
});

it('tvBroadcast without PlaylistAuth dispatches relay with null playlistAuthId', function () {
    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    Notification::make()
        ->title('Global notification')
        ->success()
        ->tvBroadcast($this->playlist, 'general');

    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $job) {
        return $job->playlistAuthId === null;
    });
});

// -- Push token registration stores playlist_auth_id ---------------------------------

it('push device token can be stored with playlist_auth_id', function () {
    $token = PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'token' => 'test-token',
        'playlist_auth_id' => $this->auth1->id,
    ]);

    expect($token->playlist_auth_id)->toBe($this->auth1->id);

    $found = PushDeviceToken::where('notifiable_type', $this->playlist->getMorphClass())
        ->where('notifiable_id', $this->playlist->id)
        ->where('playlist_auth_id', $this->auth1->id)
        ->first();

    expect($found)->not->toBeNull()
        ->and($found->token)->toBe('test-token');
});

it('transfers one physical push token to the latest credential on another playlist', function () {
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $otherAuth = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'other-transfer',
        'password' => 'other-pass',
        'enabled' => true,
    ]);
    $otherAuth->assignTo($otherPlaylist);

    $this->postJson(route('tv.push.subscribe', ['username' => 'guest1', 'password' => 'pass1']), [
        'token' => 'same-physical-device',
        'platform' => 'android',
    ])->assertOk();

    $this->postJson(route('tv.push.subscribe', ['username' => 'other-transfer', 'password' => 'other-pass']), [
        'token' => 'same-physical-device',
        'platform' => 'ios',
    ])->assertOk();

    $token = PushDeviceToken::where('token', 'same-physical-device')->sole();
    expect(PushDeviceToken::where('token', 'same-physical-device')->count())->toBe(1)
        ->and($token->notifiable_type)->toBe($otherPlaylist->getMorphClass())
        ->and($token->notifiable_id)->toBe($otherPlaylist->id)
        ->and($token->playlist_auth_id)->toBe($otherAuth->id)
        ->and($token->platform)->toBe('ios');

    $deliveredTokens = [];
    $relay = Mockery::mock(PushRelayService::class);
    $relay->shouldReceive('isEnabled')->twice()->andReturnTrue();
    $relay->shouldReceive('send')
        ->once()
        ->andReturnUsing(function (string $token) use (&$deliveredTokens): void {
            $deliveredTokens[] = $token;
        });

    (new SendPushNotificationRelay(
        notifiableType: $this->playlist->getMorphClass(),
        notifiableId: $this->playlist->id,
        title: 'Former owner',
        playlistAuthId: $this->auth1->id,
    ))->handle($relay);
    (new SendPushNotificationRelay(
        notifiableType: $otherPlaylist->getMorphClass(),
        notifiableId: $otherPlaylist->id,
        title: 'Current owner',
        playlistAuthId: $otherAuth->id,
    ))->handle($relay);

    expect($deliveredTokens)->toBe(['same-physical-device']);
});

it('transfers one physical push token to the latest credential on the same playlist', function () {
    $this->postJson(route('tv.push.subscribe', ['username' => 'guest1', 'password' => 'pass1']), [
        'token' => 'same-playlist-device',
        'platform' => 'android',
    ])->assertOk();

    $this->postJson(route('tv.push.subscribe', ['username' => 'guest2', 'password' => 'pass2']), [
        'token' => 'same-playlist-device',
        'platform' => 'ios',
    ])->assertOk();

    $token = PushDeviceToken::where('token', 'same-playlist-device')->sole();
    expect($token->playlist_auth_id)->toBe($this->auth2->id)
        ->and($token->notifiable_id)->toBe($this->playlist->id)
        ->and($token->platform)->toBe('ios');

    $deliveredTokens = [];
    $relay = Mockery::mock(PushRelayService::class);
    $relay->shouldReceive('isEnabled')->twice()->andReturnTrue();
    $relay->shouldReceive('send')
        ->once()
        ->andReturnUsing(function (string $token) use (&$deliveredTokens): void {
            $deliveredTokens[] = $token;
        });

    (new SendPushNotificationRelay(
        notifiableType: $this->playlist->getMorphClass(),
        notifiableId: $this->playlist->id,
        title: 'Former same playlist owner',
        playlistAuthId: $this->auth1->id,
    ))->handle($relay);
    (new SendPushNotificationRelay(
        notifiableType: $this->playlist->getMorphClass(),
        notifiableId: $this->playlist->id,
        title: 'Current same playlist owner',
        playlistAuthId: $this->auth2->id,
    ))->handle($relay);

    expect($deliveredTokens)->toBe(['same-playlist-device']);
});

it('keeps push token ownership canonical when registering through an alias credential', function () {
    $alias = PlaylistAlias::create([
        'name' => 'Token Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);
    $aliasAuth = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'token-alias',
        'password' => 'alias-pass',
        'enabled' => true,
    ]);
    $aliasAuth->assignTo($alias);

    $this->postJson(route('tv.push.subscribe', ['username' => 'guest1', 'password' => 'pass1']), [
        'token' => 'alias-transfer-device',
        'platform' => 'android',
    ])->assertOk();
    $this->postJson(route('tv.push.subscribe', ['username' => 'token-alias', 'password' => 'alias-pass']), [
        'token' => 'alias-transfer-device',
        'platform' => 'ios',
    ])->assertOk();

    $token = PushDeviceToken::where('token', 'alias-transfer-device')->sole();
    expect($token->notifiable_type)->toBe($alias->getMorphClass())
        ->and($token->notifiable_id)->toBe($alias->id)
        ->and($token->playlist_auth_id)->toBe($aliasAuth->id)
        ->and(PushDeviceToken::where('token', 'alias-transfer-device')->count())->toBe(1);
});

// -- Credential lifecycle -------------------------------------------------------------

it('deleting a credential removes its private notifications instead of widening them to global scope', function () {
    $privateNotification = TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'requests',
        'title' => 'Private request',
        'body' => null,
        'status' => 'success',
        'playlist_auth_id' => $this->auth1->id,
    ]);
    $globalNotification = TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Global notification',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => null,
    ]);

    $this->auth1->delete();

    expect(TvNotification::find($privateNotification->id))->toBeNull()
        ->and(TvNotification::find($globalNotification->id))->not->toBeNull();
});

it('deleting a credential removes its private push tokens instead of widening them to global scope', function () {
    $privateToken = PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth1->id,
        'token' => 'private-device',
    ]);
    $globalToken = PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => null,
        'token' => 'global-device',
    ]);

    $this->auth1->delete();

    expect(PushDeviceToken::find($privateToken->id))->toBeNull()
        ->and(PushDeviceToken::find($globalToken->id))->not->toBeNull();
});

it('registers an authenticated push unsubscribe route', function () {
    expect(Route::has('tv.push.unsubscribe'))->toBeTrue();
});

it('unsubscribes only a push token owned by the current credential', function () {
    $auth1Token = PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth1->id,
        'token' => 'device-auth1-unsubscribe',
    ]);
    $auth2Token = PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth2->id,
        'token' => 'device-auth2-keep',
    ]);
    $url = '/api/tv/guest1/pass1/push/unsubscribe';

    $this->deleteJson($url, ['token' => $auth2Token->token])
        ->assertOk();

    expect(PushDeviceToken::find($auth2Token->id))->not->toBeNull();

    $this->deleteJson($url, ['token' => $auth1Token->token])
        ->assertOk();

    expect(PushDeviceToken::find($auth1Token->id))->toBeNull()
        ->and(PushDeviceToken::find($auth2Token->id))->not->toBeNull();
});

// -- Mark-read scoping ---------------------------------------------------------------

it('playlist auth guest cannot mark-read another auth targeted notification', function () {
    $notification = TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Auth2 targeted',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => $this->auth2->id,
    ]);

    $this->postJson(route('tv.notifications.read', [
        'username' => 'guest1',
        'password' => 'pass1',
        'id' => $notification->id,
    ]))->assertNotFound();

    $notification->refresh();
    expect($notification->read_at)->toBeNull();
});

it('playlist auth guest can mark-read own-scoped notification', function () {
    $notification = TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Auth1 targeted',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => $this->auth1->id,
    ]);

    $this->postJson(route('tv.notifications.read', [
        'username' => 'guest1',
        'password' => 'pass1',
        'id' => $notification->id,
    ]))->assertOk();

    $notification->refresh();
    expect($notification->read_at)->not->toBeNull()
        ->and(TvNotificationRead::whereBelongsTo($notification)->count())->toBe(0);
});

it('playlist auth guest can mark-read global notification', function () {
    $notification = TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Global',
        'body' => null,
        'status' => 'info',
        'playlist_auth_id' => null,
    ]);

    $this->postJson(route('tv.notifications.read', [
        'username' => 'guest1',
        'password' => 'pass1',
        'id' => $notification->id,
    ]))->assertOk();

    $notification->refresh();
    expect($notification->read_at)->toBeNull()
        ->and(TvNotificationRead::whereBelongsTo($notification)->whereBelongsTo($this->auth1)->count())->toBe(1);
});
