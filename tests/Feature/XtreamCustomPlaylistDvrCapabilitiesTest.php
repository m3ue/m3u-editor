<?php

/**
 * Regression coverage for the DVR endpoints that read/write via
 * `resolveDvrSettingIds()` / `resolveDvrSettingForWrite()` when accessed through a
 * CustomPlaylist wrapping a channel whose DvrSetting lives on the source Playlist.
 *
 * XtreamCustomPlaylistDvrTest.php already covers get/cancel/delete recordings.
 * This file covers the remaining endpoints that had the same
 * `$playlist->dvrSetting` bug: storage, series-rule list/create/update/delete,
 * and EPG show search.
 */

use App\Enums\DvrRuleType;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
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
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->group = Group::factory()->for($this->user)->create();
    $this->channel = Channel::factory()
        ->for($this->playlist)
        ->for($this->group)
        ->create(['enabled' => true, 'title_custom' => 'News 24']);

    $this->setting = DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create();

    $this->customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $this->customPlaylist->channels()->attach($this->channel->id, ['sort' => 0]);

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('stopDvrBroadcast')->andReturn(true);
    $proxy->shouldReceive('cleanupDvrBroadcast')->andReturn(true);
    app()->instance(M3uProxyService::class, $proxy);
});

function customPlaylistDvrCapUrl(string $username, string $password, string $action): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ]);
}

it('reports DVR storage stats through a CustomPlaylist wrapping the source channel', function () {
    $response = $this->postJson(
        customPlaylistDvrCapUrl($this->user->name, $this->customPlaylist->uuid, 'get_dvr_storage')
    )->assertOk();

    expect($response->json('scope'))->toBe('account');
});

it('lists a series rule filed under the source playlist through a CustomPlaylist', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create(['type' => DvrRuleType::Series, 'enabled' => true, 'series_title' => 'Evening News']);

    $response = $this->postJson(
        customPlaylistDvrCapUrl($this->user->name, $this->customPlaylist->uuid, 'list_dvr_series_rules')
    )->assertOk();

    $ids = collect($response->json())->pluck('id');
    expect($ids)->toContain($rule->id);
});

it('creates a series rule through a CustomPlaylist, filed under the channel source playlist DvrSetting', function () {
    $response = $this->postJson(
        customPlaylistDvrCapUrl($this->user->name, $this->customPlaylist->uuid, 'create_dvr_series_rule'),
        ['channel_id' => (string) $this->channel->id, 'title' => 'Morning Show']
    )->assertOk()->assertJson(['success' => true]);

    $rule = DvrRecordingRule::find($response->json('rule_id'));
    expect($rule)->not->toBeNull()
        ->and($rule->dvr_setting_id)->toBe($this->setting->id);
});

it('updates a series rule filed under the source playlist through a CustomPlaylist', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create(['type' => DvrRuleType::Series, 'enabled' => true, 'series_title' => 'Evening News', 'priority' => 50]);

    $this->postJson(
        customPlaylistDvrCapUrl($this->user->name, $this->customPlaylist->uuid, 'update_dvr_series_rule'),
        ['rule_id' => $rule->id, 'priority' => 90]
    )->assertOk()->assertJson(['success' => true]);

    expect($rule->fresh()->priority)->toBe(90);
});

it('deletes a series rule filed under the source playlist through a CustomPlaylist', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create(['type' => DvrRuleType::Series, 'enabled' => true, 'series_title' => 'Evening News']);

    $this->postJson(
        customPlaylistDvrCapUrl($this->user->name, $this->customPlaylist->uuid, 'delete_dvr_series_rule'),
        ['rule_id' => $rule->id]
    )->assertOk()->assertJson(['success' => true]);

    expect(DvrRecordingRule::find($rule->id))->toBeNull();
});

it('searches EPG shows through a CustomPlaylist wrapping the source channel', function () {
    $epg = Epg::factory()->for($this->user)->create();
    $epgChannel = EpgChannel::factory()->for($this->user)->for($epg)->create(['channel_id' => 'epg-1']);
    $this->channel->update(['epg_channel_id' => $epgChannel->id]);

    EpgProgramme::factory()->for($epg)->create([
        'epg_channel_id' => 'epg-1',
        'title' => 'Breaking News Tonight',
        'start_time' => now()->addHour(),
        'end_time' => now()->addHours(2),
    ]);

    $response = $this->postJson(
        customPlaylistDvrCapUrl($this->user->name, $this->customPlaylist->uuid, 'search_epg_shows'),
        ['q' => 'Breaking News']
    )->assertOk();

    $titles = collect($response->json())->pluck('display_title');
    expect($titles)->toContain('Breaking News Tonight');
});
