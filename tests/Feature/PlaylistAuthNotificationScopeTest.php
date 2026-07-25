<?php

use App\Events\PlaylistCreated;
use App\Events\TvNotificationEvent;
use App\Jobs\SendPushNotificationRelay;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PushDeviceToken;
use App\Models\TvNotification;
use App\Models\User;
use App\Notifications\Notification;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

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

it('owner auth sees all notifications regardless of playlist_auth_id', function () {
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
        ->assertJsonCount(2, 'notifications');
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

    $query = PushDeviceToken::where('notifiable_type', $this->playlist->getMorphClass())
        ->where('notifiable_id', $this->playlist->id)
        ->where('playlist_auth_id', $this->auth1->id);

    expect($query->count())->toBe(1)
        ->and($query->first()->token)->toBe('device-auth1');
});

it('push relay with null playlistAuthId only reaches global devices', function () {
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

    $query = PushDeviceToken::where('notifiable_type', $this->playlist->getMorphClass())
        ->where('notifiable_id', $this->playlist->id)
        ->whereNull('playlist_auth_id');

    expect($query->count())->toBe(1)
        ->and($query->first()->token)->toBe('device-global');
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
    expect($notification->read_at)->not->toBeNull();
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
    expect($notification->read_at)->not->toBeNull();
});
