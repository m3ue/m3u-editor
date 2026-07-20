<?php

/**
 * Regression coverage for DVR / Requests / AIOStreams parity on CustomPlaylist
 * and MergedPlaylist: each of these playlist types now owns its own DvrSetting,
 * PlaylistRequestSetting, and aiostreams_integration_id, exactly like a real
 * Playlist, via the polymorphic-ish ownership columns on dvr_settings and
 * playlist_request_settings (playlist_id / custom_playlist_id / merged_playlist_id).
 */

use App\Models\ArrIntegration;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\PlaylistRequestSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
});

it('resolves a merged playlist DvrSetting via its own owner column', function () {
    $merged = MergedPlaylist::factory()->for($this->user)->create();

    $setting = DvrSetting::create([
        'merged_playlist_id' => $merged->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    expect($merged->refresh()->dvrSetting->is($setting))->toBeTrue()
        ->and($setting->owner()->is($merged))->toBeTrue()
        ->and($setting->playlist_id)->toBeNull()
        ->and($setting->custom_playlist_id)->toBeNull();
});

it('resolves a custom playlist PlaylistRequestSetting via its own owner column', function () {
    $custom = CustomPlaylist::factory()->for($this->user)->create();

    $setting = PlaylistRequestSetting::create([
        'custom_playlist_id' => $custom->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    expect($custom->refresh()->requestSetting->is($setting))->toBeTrue()
        ->and($setting->owner()->is($custom))->toBeTrue();
});

it('scopes ownerChannelsSubquery and ownerPlaylistIds across real playlists reachable through a merged playlist', function () {
    $realA = Playlist::factory()->for($this->user)->create();
    $realB = Playlist::factory()->for($this->user)->create();
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach([$realA->id, $realB->id]);

    $group = Group::factory()->for($this->user)->create();
    $channelA = Channel::factory()->for($realA)->for($group)->create(['enabled' => true]);
    $channelB = Channel::factory()->for($realB)->for($group)->create(['enabled' => true]);
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $unrelatedChannel = Channel::factory()->for($otherPlaylist)->for($group)->create(['enabled' => true]);

    $setting = DvrSetting::create([
        'merged_playlist_id' => $merged->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    // The subquery is DB-side (never materializes ids into PHP), so assert it
    // by using it to scope a real query rather than plucking it directly.
    $scopedIds = Channel::whereIn('id', $setting->ownerChannelsSubquery())->pluck('id')->all();

    expect($scopedIds)->toEqualCanonicalizing([$channelA->id, $channelB->id])
        ->and($scopedIds)->not->toContain($unrelatedChannel->id)
        ->and($setting->ownerPlaylistIds())->toEqualCanonicalizing([$realA->id, $realB->id]);
});

it('advertises dvr, requests, and aiostreams for a playlist_auth assigned directly to a merged playlist', function () {
    $realPlaylist = Playlist::factory()->for($this->user)->create();
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($realPlaylist->id);

    $group = Group::factory()->for($this->user)->create();
    Channel::factory()->for($realPlaylist)->for($group)->create(['enabled' => true]);

    DvrSetting::create([
        'merged_playlist_id' => $merged->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    PlaylistRequestSetting::create([
        'merged_playlist_id' => $merged->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    ArrIntegration::factory()->for($this->user)->radarr()->guestEnabled()->create();

    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'aiostreams',
        'enabled' => true,
    ]);
    $merged->update(['aiostreams_integration_id' => $integration->id]);

    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'dvr_enabled' => true,
        'request_enabled' => true,
        'aiostreams_enabled' => true,
        'username' => 'merged-parity-user',
        'password' => 'merged-parity-pass',
    ]);
    $auth->assignTo($merged);

    $response = $this->getJson('/player_api.php?'.http_build_query([
        'username' => 'merged-parity-user',
        'password' => 'merged-parity-pass',
        'action' => 'panel',
    ]));

    $response->assertOk();
    expect($response->json('m3u_editor.features'))
        ->toContain('dvr')
        ->toContain('requests')
        ->toContain('aiostreams');
});

it('schedules a dvr recording through a merged playlist auth using the owner-resolved channel scope', function () {
    $realPlaylist = Playlist::factory()->for($this->user)->create();
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($realPlaylist->id);

    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($realPlaylist)->for($group)->create(['enabled' => true, 'title_custom' => 'News 24']);

    DvrSetting::create([
        'merged_playlist_id' => $merged->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'dvr_enabled' => true,
        'username' => 'merged-dvr-user',
        'password' => 'merged-dvr-pass',
    ]);
    $auth->assignTo($merged);

    $start = now()->addHour()->startOfMinute();
    $end = $start->copy()->addMinutes(45);

    $response = $this->postJson('/player_api.php?'.http_build_query([
        'username' => 'merged-dvr-user',
        'password' => 'merged-dvr-pass',
        'action' => 'schedule_dvr',
    ]), [
        'channel_id' => (string) $channel->id,
        'title' => 'Evening News',
        'start_time' => $start->toIso8601String(),
        'end_time' => $end->toIso8601String(),
    ]);

    $response->assertOk()->assertJson(['success' => true]);
});

it('inherits dvr/request/aiostreams settings on a PlaylistAlias from its effective playlist', function () {
    $realPlaylist = Playlist::factory()->for($this->user)->create();

    $dvrSetting = DvrSetting::create([
        'playlist_id' => $realPlaylist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    $requestSetting = PlaylistRequestSetting::create([
        'playlist_id' => $realPlaylist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'aiostreams',
        'enabled' => true,
    ]);
    $realPlaylist->update(['aiostreams_integration_id' => $integration->id]);

    $alias = PlaylistAlias::create([
        'playlist_id' => $realPlaylist->id,
        'user_id' => $this->user->id,
        'name' => 'Test Alias',
        'uuid' => Str::uuid()->toString(),
    ]);

    expect($alias->dvrSetting?->is($dvrSetting))->toBeTrue()
        ->and($alias->requestSetting?->is($requestSetting))->toBeTrue()
        ->and($alias->aiostreamsIntegration?->is($integration))->toBeTrue();
});
