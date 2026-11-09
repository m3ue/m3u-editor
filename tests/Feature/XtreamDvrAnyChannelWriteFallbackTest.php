<?php

/**
 * Issue #1756: a Custom Playlist with no DvrSetting of its own, whose channels come
 * from two source playlists, rejected an any-channel ("no channel_id") DVR series rule
 * with "DVR is not enabled for this playlist" whenever the first setting id resolved by
 * DvrAccessScope::settingIdsForPlaylist() happened to be disabled, even though a later
 * resolved setting was enabled. settingForWriteOnPlaylist()'s final any-channel fallback
 * returned DvrSetting::find($settingIds[0]) unconditionally, instead of an actually
 * enabled one.
 */

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('stopDvrBroadcast')->andReturn(true);
    $proxy->shouldReceive('cleanupDvrBroadcast')->andReturn(true);
    app()->instance(M3uProxyService::class, $proxy);
});

function anyChannelDvrUrl(string $username, string $password, string $action): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ]);
}

it('creates an any-channel series rule under the enabled source when the first resolved setting is disabled', function () {
    $playlistA = Playlist::factory()->for($this->user)->create();
    $playlistB = Playlist::factory()->for($this->user)->create();
    $group = Group::factory()->for($this->user)->create();

    Channel::factory()->for($playlistA)->for($group)->create(['enabled' => true]);
    Channel::factory()->for($playlistB)->for($group)->create(['enabled' => true]);

    // Created in this order so playlistA's (disabled) setting id resolves before
    // playlistB's (enabled) one - the exact ordering the issue reproduces.
    $disabledSetting = DvrSetting::factory()->for($this->user)->for($playlistA)->create(['enabled' => false]);
    $enabledSetting = DvrSetting::factory()->enabled()->for($this->user)->for($playlistB)->create();

    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $customPlaylist->channels()->attach($playlistA->channels()->first()->id, ['sort' => 0]);
    $customPlaylist->channels()->attach($playlistB->channels()->first()->id, ['sort' => 1]);

    $response = $this->postJson(
        anyChannelDvrUrl($this->user->name, $customPlaylist->uuid, 'create_dvr_series_rule'),
        ['title' => 'Morning Show']
    )->assertOk()->assertJson(['success' => true]);

    $rule = DvrRecordingRule::find($response->json('rule_id'));
    expect($rule)->not->toBeNull()
        ->and($rule->dvr_setting_id)->toBe($enabledSetting->id)
        ->and($rule->channel_id)->toBeNull()
        ->and($rule->user_id)->toBe($this->user->id);
});

it('still rejects an any-channel series rule when every resolved source setting is disabled', function () {
    $playlistA = Playlist::factory()->for($this->user)->create();
    $group = Group::factory()->for($this->user)->create();
    Channel::factory()->for($playlistA)->for($group)->create(['enabled' => true]);
    DvrSetting::factory()->for($this->user)->for($playlistA)->create(['enabled' => false]);

    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $customPlaylist->channels()->attach($playlistA->channels()->first()->id, ['sort' => 0]);

    $this->postJson(
        anyChannelDvrUrl($this->user->name, $customPlaylist->uuid, 'create_dvr_series_rule'),
        ['title' => 'Morning Show']
    )->assertStatus(403);

    expect(DvrRecordingRule::count())->toBe(0);
});
