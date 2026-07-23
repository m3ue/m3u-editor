<?php

use App\Events\TvNotificationEvent;
use App\Jobs\SendPushNotificationRelay;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PushDeviceToken;
use App\Models\TvNotification;
use App\Models\User;
use App\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->auth = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'tv_user',
        'password' => 'tv_pass',
        'enabled' => true,
    ]);
    $this->auth->assignTo($this->playlist);
});

// ── tvBroadcast() ──────────────────────────────────────────────────────────────

it('tvBroadcast creates a TvNotification with the correct channel', function () {
    Event::fake([TvNotificationEvent::class]);

    Notification::make()
        ->title('Sync complete')
        ->body('Your playlist has been synced.')
        ->success()
        ->tvBroadcast($this->playlist, 'sync_complete');

    expect(TvNotification::count())->toBe(1);

    $record = TvNotification::first();
    expect($record->notifiable_type)->toBe($this->playlist->getMorphClass())
        ->and($record->notifiable_id)->toBe($this->playlist->id)
        ->and($record->channel)->toBe('sync_complete')
        ->and($record->admin_only)->toBeFalse()
        ->and($record->title)->toBe('Sync complete')
        ->and($record->status)->toBe('success');
});

it('tvBroadcast dispatches TvNotificationEvent with correct payload', function () {
    Event::fake([TvNotificationEvent::class]);

    Notification::make()
        ->title('Error occurred')
        ->body('Something failed.')
        ->danger()
        ->tvBroadcast($this->playlist, 'error');

    Event::assertDispatched(TvNotificationEvent::class, function ($event) {
        return $event->notifiableType === $this->playlist->getMorphClass()
            && $event->notifiableUuid === $this->playlist->uuid
            && $event->adminOnly === false
            && $event->channel === 'error'
            && $event->title === 'Error occurred'
            && $event->status === 'danger';
    });
});

it('tvBroadcast defaults channel to general and adminOnly to false', function () {
    Event::fake([TvNotificationEvent::class]);

    Notification::make()->title('Hello')->info()->tvBroadcast($this->playlist);

    $record = TvNotification::first();
    expect($record->channel)->toBe('general')
        ->and($record->admin_only)->toBeFalse();
});

it('tvBroadcast with adminOnly:true stores admin_only flag and broadcasts only to admin channel', function () {
    Event::fake([TvNotificationEvent::class]);

    Notification::make()
        ->title('Admin alert')
        ->warning()
        ->tvBroadcast($this->playlist, 'billing', adminOnly: true);

    $record = TvNotification::first();
    expect($record->admin_only)->toBeTrue()
        ->and($record->channel)->toBe('billing');

    Event::assertDispatched(TvNotificationEvent::class, function ($event) {
        $channels = array_map(fn ($c) => $c->name, $event->broadcastOn());

        return $event->adminOnly === true
            && $channels === ["private-tv.playlist-admin.{$this->playlist->uuid}"];
    });
});

it('standard tvBroadcast broadcasts on both playlist and admin channels', function () {
    Event::fake([TvNotificationEvent::class]);

    Notification::make()->title('News')->info()->tvBroadcast($this->playlist, 'general');

    Event::assertDispatched(TvNotificationEvent::class, function ($event) {
        $channels = array_map(fn ($c) => $c->name, $event->broadcastOn());

        return $channels === [
            "private-tv.playlist.{$this->playlist->uuid}",
            "private-tv.playlist-admin.{$this->playlist->uuid}",
        ];
    });
});

it('tvBroadcast dispatches SendPushNotificationRelay with the notification payload', function () {
    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    Notification::make()
        ->title('Sync complete')
        ->body('Your playlist has been synced.')
        ->success()
        ->tvBroadcast($this->playlist, 'sync_complete');

    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $job) {
        return $job->notifiableType === $this->playlist->getMorphClass()
            && $job->notifiableId === $this->playlist->id
            && $job->title === 'Sync complete'
            && $job->body === 'Your playlist has been synced.';
    });
});

// ── GET /api/tv/{username}/{password}/notifications ────────────────────────────

it('GET notifications returns unread notifications for the playlist', function () {
    TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Hello',
        'body' => null,
        'status' => 'info',
    ]);

    $this->getJson(route('tv.notifications', ['username' => 'tv_user', 'password' => 'tv_pass']))
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.title', 'Hello')
        ->assertJsonPath('is_admin', false)
        ->assertJsonPath('reverb.channel', "private-tv.playlist.{$this->playlist->uuid}");
});

it('GET notifications filters by channel when channels[] passed', function () {
    foreach (['error', 'sync_complete', 'general'] as $ch) {
        TvNotification::create([
            'notifiable_type' => $this->playlist->getMorphClass(),
            'notifiable_id' => $this->playlist->id,
            'channel' => $ch,
            'title' => "Title {$ch}",
            'body' => null,
            'status' => 'info',
        ]);
    }

    $base = route('tv.notifications', ['username' => 'tv_user', 'password' => 'tv_pass']);

    $this->getJson($base.'?'.http_build_query(['channels' => ['error']]))
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.channel', 'error');
});

it('GET notifications does not return read notifications', function () {
    TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Already read',
        'body' => null,
        'status' => 'info',
        'read_at' => now(),
    ]);

    $this->getJson(route('tv.notifications', ['username' => 'tv_user', 'password' => 'tv_pass']))
        ->assertOk()
        ->assertJsonCount(0, 'notifications');
});

it('two playlists with different auth see only their own notifications', function () {
    $playlist2 = Playlist::factory()->for($this->user)->create();
    $auth2 = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'tv_user_2',
        'password' => 'tv_pass_2',
        'enabled' => true,
    ]);
    $auth2->assignTo($playlist2);

    TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'For playlist 1',
        'body' => null,
        'status' => 'info',
    ]);

    TvNotification::create([
        'notifiable_type' => $playlist2->getMorphClass(),
        'notifiable_id' => $playlist2->id,
        'channel' => 'general',
        'title' => 'For playlist 2',
        'body' => null,
        'status' => 'info',
    ]);

    $this->getJson(route('tv.notifications', ['username' => 'tv_user', 'password' => 'tv_pass']))
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.title', 'For playlist 1');

    $this->getJson(route('tv.notifications', ['username' => 'tv_user_2', 'password' => 'tv_pass_2']))
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.title', 'For playlist 2');
});

// ── Admin scope ────────────────────────────────────────────────────────────────

it('owner_auth with admin user returns admin scope and admin channel', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $playlist = Playlist::factory()->for($admin)->create();

    $this->getJson(route('tv.notifications', [
        'username' => $admin->name,
        'password' => $playlist->uuid,
    ]))
        ->assertOk()
        ->assertJsonPath('is_admin', true)
        ->assertJsonPath('reverb.channel', "private-tv.playlist-admin.{$playlist->uuid}");
});

it('admin scope sees admin_only notifications', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $playlist = Playlist::factory()->for($admin)->create();

    TvNotification::create([
        'notifiable_type' => $playlist->getMorphClass(),
        'notifiable_id' => $playlist->id,
        'channel' => 'billing',
        'admin_only' => true,
        'title' => 'Admin only',
        'body' => null,
        'status' => 'warning',
    ]);

    TvNotification::create([
        'notifiable_type' => $playlist->getMorphClass(),
        'notifiable_id' => $playlist->id,
        'channel' => 'general',
        'admin_only' => false,
        'title' => 'Standard',
        'body' => null,
        'status' => 'info',
    ]);

    $this->getJson(route('tv.notifications', ['username' => $admin->name, 'password' => $playlist->uuid]))
        ->assertOk()
        ->assertJsonCount(2, 'notifications');
});

it('non-admin playlist scope does not see admin_only notifications', function () {
    TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'billing',
        'admin_only' => true,
        'title' => 'Admin only',
        'body' => null,
        'status' => 'warning',
    ]);

    TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'admin_only' => false,
        'title' => 'Standard',
        'body' => null,
        'status' => 'info',
    ]);

    $this->getJson(route('tv.notifications', ['username' => 'tv_user', 'password' => 'tv_pass']))
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.title', 'Standard');
});

it('owner_auth with non-admin user returns playlist scope', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    $this->getJson(route('tv.notifications', [
        'username' => $this->user->name,
        'password' => $playlist->uuid,
    ]))
        ->assertOk()
        ->assertJsonPath('is_admin', false)
        ->assertJsonPath('reverb.channel', "private-tv.playlist.{$playlist->uuid}");
});

// ── POST /api/tv/{username}/{password}/notifications/{id}/read ─────────────────

it('POST mark-read marks notification as read', function () {
    $notification = TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'general',
        'title' => 'Mark me',
        'body' => null,
        'status' => 'info',
    ]);

    $url = '/api/tv/tv_user/tv_pass/notifications/'.$notification->id.'/read';

    $this->postJson($url)->assertOk()->assertJsonPath('ok', true);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('non-admin cannot mark-read an admin_only notification', function () {
    $notification = TvNotification::create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'channel' => 'billing',
        'admin_only' => true,
        'title' => 'Admin only',
        'body' => null,
        'status' => 'warning',
    ]);

    $url = '/api/tv/tv_user/tv_pass/notifications/'.$notification->id.'/read';

    $this->postJson($url)->assertNotFound();
});

// ── POST /api/tv/{username}/{password}/broadcasting/auth ───────────────────────

it('broadcastingAuth returns HMAC for playlist scope channel', function () {
    $socketId = '123456.78910';
    $channelName = "private-tv.playlist.{$this->playlist->uuid}";
    $secret = config('broadcasting.connections.reverb.secret');
    $key = config('broadcasting.connections.reverb.key');

    $expectedSig = hash_hmac('sha256', "{$socketId}:{$channelName}", $secret);

    $this->postJson(route('tv.broadcasting.auth', ['username' => 'tv_user', 'password' => 'tv_pass']), [
        'socket_id' => $socketId,
        'channel_name' => $channelName,
    ])->assertOk()
        ->assertJsonPath('auth', "{$key}:{$expectedSig}");
});

it('broadcastingAuth returns HMAC for admin scope channel', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $playlist = Playlist::factory()->for($admin)->create();

    $socketId = '123456.78910';
    $channelName = "private-tv.playlist-admin.{$playlist->uuid}";
    $secret = config('broadcasting.connections.reverb.secret');
    $key = config('broadcasting.connections.reverb.key');

    $expectedSig = hash_hmac('sha256', "{$socketId}:{$channelName}", $secret);

    $this->postJson(route('tv.broadcasting.auth', ['username' => $admin->name, 'password' => $playlist->uuid]), [
        'socket_id' => $socketId,
        'channel_name' => $channelName,
    ])->assertOk()
        ->assertJsonPath('auth', "{$key}:{$expectedSig}");
});

it('broadcastingAuth returns 403 for mismatched channel', function () {
    $this->postJson(route('tv.broadcasting.auth', ['username' => 'tv_user', 'password' => 'tv_pass']), [
        'socket_id' => '123456.78910',
        'channel_name' => 'private-tv.playlist.99999',
    ])->assertForbidden();
});

it('broadcastingAuth returns 403 when playlist scope tries to use admin channel', function () {
    $this->postJson(route('tv.broadcasting.auth', ['username' => 'tv_user', 'password' => 'tv_pass']), [
        'socket_id' => '123456.78910',
        'channel_name' => "private-tv.playlist-admin.{$this->playlist->uuid}",
    ])->assertForbidden();
});

// ── POST /api/tv/{username}/{password}/push/subscribe ──────────────────────────

it('push/subscribe registers a new device token for the playlist', function () {
    $url = route('tv.push.subscribe', ['username' => 'tv_user', 'password' => 'tv_pass']);

    $this->postJson($url, ['token' => 'fcm-token-abc', 'platform' => 'android'])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(PushDeviceToken::count())->toBe(1);

    $device = PushDeviceToken::first();
    expect($device->notifiable_type)->toBe($this->playlist->getMorphClass())
        ->and($device->notifiable_id)->toBe($this->playlist->id)
        ->and($device->token)->toBe('fcm-token-abc')
        ->and($device->platform)->toBe('android')
        ->and($device->last_seen_at)->not->toBeNull();
});

it('push/subscribe re-registering the same token updates it instead of duplicating', function () {
    $url = route('tv.push.subscribe', ['username' => 'tv_user', 'password' => 'tv_pass']);

    $this->postJson($url, ['token' => 'fcm-token-abc', 'platform' => 'ios'])->assertOk();
    $this->postJson($url, ['token' => 'fcm-token-abc', 'platform' => 'ios'])->assertOk();

    expect(PushDeviceToken::count())->toBe(1);
});

it('push/subscribe rejects an invalid platform', function () {
    $url = route('tv.push.subscribe', ['username' => 'tv_user', 'password' => 'tv_pass']);

    $this->postJson($url, ['token' => 'fcm-token-abc', 'platform' => 'windows'])
        ->assertUnprocessable();

    expect(PushDeviceToken::count())->toBe(0);
});

it('push/subscribe requires a token', function () {
    $url = route('tv.push.subscribe', ['username' => 'tv_user', 'password' => 'tv_pass']);

    $this->postJson($url, ['platform' => 'ios'])->assertUnprocessable();
});

it('push/subscribe returns 401 with invalid credentials', function () {
    $url = route('tv.push.subscribe', ['username' => 'wrong', 'password' => 'wrong']);

    $this->postJson($url, ['token' => 'fcm-token-abc', 'platform' => 'ios'])
        ->assertUnauthorized();
});

it('TV endpoints return 401 with invalid credentials', function () {
    $this->getJson(route('tv.notifications', ['username' => 'wrong', 'password' => 'wrong']))
        ->assertUnauthorized();

    $this->postJson(route('tv.broadcasting.auth', ['username' => 'wrong', 'password' => 'wrong']), [
        'socket_id' => '123456.78910',
        'channel_name' => 'private-tv.playlist.1',
    ])->assertUnauthorized();
});
