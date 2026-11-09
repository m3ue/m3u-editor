<?php

/**
 * Proxy > Watch History: sessions recorded from m3u-proxy webhooks, kept in
 * step with the proxy's client list, and shown on the Watch History page.
 */

use App\Filament\Clusters\Settings\Pages\ManageProxySettings;
use App\Filament\Resources\StreamWatchSessions\Pages\ListStreamWatchSessions;
use App\Filament\Resources\StreamWatchSessions\StreamWatchSessionResource;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\StreamWatchSession;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Services\StreamHistoryRecorder;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('proxy.m3u_proxy_host', 'http://test-proxy.example');
    config()->set('proxy.m3u_proxy_port', null);
    config()->set('proxy.m3u_proxy_token', 'proxy-token');
    config()->set('proxy.m3u_resolver_url', 'http://editor.internal:36400');

    // Reload: the settings were resolved before the first test's database refresh.
    $settings = app(GeneralSettings::class)->refresh();
    $settings->proxy_track_watch_history = true;
    $settings->save();

    $this->user = User::factory()->create(['permissions' => ['use_proxy']]);
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->playlistAuth = PlaylistAuth::create([
        'name' => 'Living Room',
        'username' => 'living',
        'password' => 'secret',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->viewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'Living Room',
        'is_admin' => false,
        'playlist_auth_id' => $this->playlistAuth->id,
        'viewerable_type' => $this->playlist->getMorphClass(),
        'viewerable_id' => $this->playlist->id,
    ]);
    $this->movie = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'year' => 1999,
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
    ]);

    $this->recorder = app(StreamHistoryRecorder::class);
});

function connectedWebhook(string $streamId, string $clientId, array $metadata, ?string $username = 'living'): array
{
    return [
        'client_id' => $clientId,
        'user_agent' => 'TiviMate/5.1.6 (Android 12)',
        'ip_address' => '192.0.2.10',
        'username' => $username,
        'metadata' => $metadata,
    ];
}

it('opens a session for the client, tied to its Playlist Auth and viewer', function () {
    $this->recorder->handleWebhook('client_connected', 'stream-1', connectedWebhook('stream-1', 'client-1', [
        'type' => 'channel',
        'id' => $this->movie->id,
        'playlist_uuid' => $this->playlist->uuid,
        'profile_id' => 4,
        'transcoding' => true,
    ]));

    $session = StreamWatchSession::sole();
    expect($session)
        ->user_id->toBe($this->user->id)
        ->content_type->toBe('vod')
        ->title->toBe('The Matrix')
        ->year->toBe(1999)
        ->tmdb_id->toBe(603)
        ->imdb_id->toBe('tt0133093')
        ->playlist_auth_id->toBe($this->playlistAuth->id)
        ->playlist_viewer_id->toBe($this->viewer->id)
        ->is_transcoded->toBeTrue()
        ->stream_profile_id->toBe(4)
        ->isOpen()->toBeTrue()
        ->and($session->playlist->is($this->playlist))->toBeTrue()
        ->and($session->clientName())->toBe('TiviMate');
});

it('closes the session with how long it ran and the data sent', function () {
    $this->recorder->handleWebhook('client_connected', 'stream-1', connectedWebhook('stream-1', 'client-1', [
        'type' => 'channel', 'id' => $this->movie->id, 'playlist_uuid' => $this->playlist->uuid,
    ]));
    $this->travel(42)->minutes();

    $this->recorder->handleWebhook('client_disconnected', 'stream-1', ['client_id' => 'client-1', 'bytes_served' => 1_500_000_000]);

    expect(StreamWatchSession::sole())
        ->isOpen()->toBeFalse()
        ->duration_seconds->toBe(42 * 60)
        ->bytes_served->toBe(1_500_000_000)
        ->end_reason->toBe('disconnected');
});

it('attributes each client on a shared stream to its own login', function () {
    $other = PlaylistAuth::create([
        'name' => 'Bedroom', 'username' => 'bedroom', 'password' => 'secret', 'enabled' => true, 'user_id' => $this->user->id,
    ]);
    // The pooled stream was started by Living Room; Bedroom joins it.
    $metadata = ['type' => 'channel', 'id' => $this->movie->id, 'playlist_uuid' => $this->playlist->uuid, 'playlist_auth_id' => (string) $this->playlistAuth->id];

    $this->recorder->handleWebhook('client_connected', 'pooled', connectedWebhook('pooled', 'client-a', $metadata, 'living'));
    $this->recorder->handleWebhook('client_connected', 'pooled', connectedWebhook('pooled', 'client-b', $metadata, 'bedroom'));

    expect(StreamWatchSession::orderBy('id')->pluck('playlist_auth_id')->all())->toBe([$this->playlistAuth->id, $other->id]);
});

it('records episodes with their series, season and episode', function () {
    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'name' => 'Breaking Bad', 'imdb_id' => 'tt0903747']);
    $episode = Episode::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'series_id' => $series->id,
        'title' => 'Grilled', 'season' => 2, 'episode_num' => 2, 'tmdb_id' => 62092,
    ]);

    $this->recorder->handleWebhook('client_connected', 'ep', connectedWebhook('ep', 'client-1', [
        'type' => 'episode', 'id' => $episode->id, 'playlist_uuid' => $this->playlist->uuid,
    ]));

    expect(StreamWatchSession::sole())
        ->content_type->toBe('episode')
        ->title->toBe('Breaking Bad')
        ->episode_title->toBe('Grilled')
        ->season_number->toBe(2)
        ->episode_number->toBe(2)
        ->series_id->toBe($series->id)
        ->imdb_id->toBe('tt0903747');
});

it('records nothing while tracking is off, or for streams that are not channels or episodes', function () {
    $this->recorder->handleWebhook('client_connected', 'bcast', connectedWebhook('bcast', 'client-1', ['type' => 'broadcast', 'network_id' => 'abc']));
    expect(StreamWatchSession::count())->toBe(0);

    $settings = app(GeneralSettings::class);
    $settings->proxy_track_watch_history = false;
    $settings->save();

    $this->recorder->handleWebhook('client_connected', 'stream-1', connectedWebhook('stream-1', 'client-1', [
        'type' => 'channel', 'id' => $this->movie->id, 'playlist_uuid' => $this->playlist->uuid,
    ]));
    expect(StreamWatchSession::count())->toBe(0);
});

it('is fed by the proxy webhook endpoint', function () {
    $this->withoutMiddleware()
        ->postJson(route('m3u-proxy.webhook'), [
            'event_type' => 'client_connected',
            'stream_id' => 'stream-1',
            'data' => connectedWebhook('stream-1', 'client-1', [
                'type' => 'channel', 'id' => $this->movie->id, 'playlist_uuid' => $this->playlist->uuid,
            ]),
        ])
        ->assertOk();

    expect(StreamWatchSession::count())->toBe(1);
});

it('ends sessions the proxy never reported closed, with an unknown length', function () {
    $abandoned = StreamWatchSession::factory()->for($this->user)->create(['started_at' => now()->subHours(30)]);
    $current = StreamWatchSession::factory()->for($this->user)->create(['started_at' => now()->subHours(3)]);

    $this->artisan('app:close-abandoned-stream-history')->assertSuccessful();

    expect($abandoned->fresh())
        ->isOpen()->toBeFalse()
        ->end_reason->toBe('no_disconnect')
        ->watchedSeconds()->toBeNull()
        ->and($current->fresh()->isOpen())->toBeTrue();
});

it('passes the webhook check once the proxy delivers a test event', function () {
    $webhookUrl = app(M3uProxyService::class)->getWebhookUrl();
    Http::fake([
        'test-proxy.example/webhooks' => Http::response(['webhooks' => [['url' => $webhookUrl]]]),
        'test-proxy.example/webhooks/test*' => Http::response(['message' => 'sent']),
    ]);

    expect(app(M3uProxyService::class)->checkWebhook())->toMatchArray(['ok' => true, 'reason' => 'ok']);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/webhooks/test?webhook_url='));
});

it('explains why webhooks are not reaching the editor', function () {
    $webhookUrl = app(M3uProxyService::class)->getWebhookUrl();
    Http::fake([
        'test-proxy.example/webhooks' => Http::response(['webhooks' => [['url' => $webhookUrl]]]),
        'test-proxy.example/webhooks/test*' => Http::response(['detail' => 'failed'], 502),
    ]);

    $result = app(M3uProxyService::class)->checkWebhook();

    expect($result)->toMatchArray(['ok' => false, 'reason' => 'delivery_failed'])
        ->and($result['message'])->toContain('editor.internal:36400')
        ->and($result['message'])->not->toContain('api_token');
});

it('needs a Resolver URL for webhooks', function () {
    config(['proxy.m3u_resolver_url' => null]);

    expect(app(M3uProxyService::class)->checkWebhook())->toMatchArray(['ok' => false, 'reason' => 'not_configured']);
    Http::assertNothingSent();
});

it('registers a missing webhook before testing it', function () {
    Http::fake([
        'test-proxy.example/webhooks' => Http::sequence()
            ->push(['webhooks' => []])
            ->push(['webhooks' => []])
            ->push(['message' => 'registered'])
            ->push(['webhooks' => [['url' => app(M3uProxyService::class)->getWebhookUrl()]]]),
        'test-proxy.example/webhooks/test*' => Http::response(['message' => 'sent']),
    ]);

    expect(app(M3uProxyService::class)->checkWebhook())->toMatchArray(['ok' => true]);
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/webhooks')
        && in_array('client_connected', $request['events'] ?? [], true));
});

it('prunes sessions older than the retention setting', function () {
    $settings = app(GeneralSettings::class);
    $settings->proxy_watch_history_retention_days = 7;
    $settings->save();

    $old = StreamWatchSession::factory()->for($this->user)->ended()->create(['started_at' => now()->subDays(8)]);
    $recent = StreamWatchSession::factory()->for($this->user)->ended()->create(['started_at' => now()->subDays(6)]);

    $this->artisan('model:prune', ['--model' => [StreamWatchSession::class]])->assertSuccessful();

    expect($old->fresh())->toBeNull()
        ->and($recent->fresh())->not->toBeNull();
});

it('shows only the signed-in user\'s history, and only while tracking is on', function () {
    $this->actingAs($this->user);
    $mine = StreamWatchSession::factory()->for($this->user)->create(['title' => 'Mine']);
    $theirs = StreamWatchSession::factory()->create(['title' => 'Theirs']);

    Livewire::test(ListStreamWatchSessions::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    $settings = app(GeneralSettings::class);
    $settings->proxy_track_watch_history = false;
    $settings->save();

    expect(StreamWatchSessionResource::canAccess())->toBeFalse();
});

it('only turns tracking on once webhooks reach the editor', function () {
    $admin = User::factory()->admin()->create(['permissions' => ['use_proxy']]);
    $this->actingAs($admin);
    $settings = app(GeneralSettings::class)->refresh();
    $settings->proxy_track_watch_history = false;
    $settings->save();
    $webhookUrl = app(M3uProxyService::class)->getWebhookUrl();
    Http::fake([
        'test-proxy.example/webhooks' => Http::response(['webhooks' => [['url' => $webhookUrl]]]),
        'test-proxy.example/webhooks/test*' => Http::response(['detail' => 'failed'], 502),
    ]);

    Livewire::test(ManageProxySettings::class)
        ->fillForm(['proxy_track_watch_history' => true])
        ->call('save')
        ->assertHasFormErrors(['proxy_track_watch_history']);

    expect(app(GeneralSettings::class)->refresh()->proxy_track_watch_history)->toBeFalse();
});

it('turns tracking on and sets retention from Settings > Proxy', function () {
    $admin = User::factory()->admin()->create(['permissions' => ['use_proxy']]);
    $this->actingAs($admin);
    Http::fake([
        'test-proxy.example/webhooks' => Http::response(['webhooks' => [['url' => app(M3uProxyService::class)->getWebhookUrl()]]]),
        'test-proxy.example/webhooks/test*' => Http::response(['message' => 'sent']),
    ]);

    $settings = app(GeneralSettings::class)->refresh();
    $settings->proxy_track_watch_history = false;
    $settings->save();

    Livewire::test(ManageProxySettings::class)
        ->assertFormFieldIsHidden('proxy_watch_history_retention_days')
        ->fillForm(['proxy_track_watch_history' => true])
        ->assertFormFieldIsVisible('proxy_watch_history_retention_days')
        ->fillForm(['proxy_watch_history_retention_days' => -1])
        ->call('save')
        ->assertHasFormErrors(['proxy_watch_history_retention_days' => 'min'])
        ->fillForm(['proxy_watch_history_retention_days' => 14])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(GeneralSettings::class)->refresh();
    expect($settings->proxy_track_watch_history)->toBeTrue()
        ->and($settings->proxy_watch_history_retention_days)->toBe(14)
        ->and(StreamWatchSessionResource::canAccess())->toBeTrue();
});

it('keeps every session when retention is 0', function () {
    $settings = app(GeneralSettings::class);
    $settings->proxy_watch_history_retention_days = 0;
    $settings->save();

    $ancient = StreamWatchSession::factory()->for($this->user)->ended()->create(['started_at' => now()->subYears(3)]);

    $this->artisan('model:prune', ['--model' => [StreamWatchSession::class]])->assertSuccessful();

    expect($ancient->fresh())->not->toBeNull();
});
