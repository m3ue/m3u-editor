<?php

/**
 * Regression coverage for #1827 - two EPGs that reuse the same XMLTV channel id.
 *
 * XMLTV ids are only unique within one EPG. A country guide (EPG A) and a custom
 * override guide (EPG B) can both contain "Channel4.uk" with different titles in
 * the same slot. A channel mapped to EPG B must only ever see EPG B's programmes,
 * in every place that looks programmes up by the channel's mapping:
 *   - Xtream `search_epg_shows`
 *   - DvrSchedulerService series matching (pinned and "any channel" rules)
 *   - the series rule airings preview (HasDvrMatchedAirings)
 *   - Browse Shows (with a tvg-id fallback only for unmapped channels)
 */

use App\Enums\DvrRecordingStatus;
use App\Filament\Pages\BrowseShows;
use App\Filament\Resources\DvrRecordingRules\DvrRecordingRuleResource;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\DvrSchedulerService;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
    config()->set('dvr.dvr_enabled', true);
    config()->set('proxy.proxy_integration_enabled', true);
    app()->instance(M3uProxyService::class, Mockery::mock(M3uProxyService::class));

    $this->user = User::factory()->create(['permissions' => ['use_dvr']]);
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->group = Group::factory()->for($this->user)->create();
    $this->setting = DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create([
        'default_start_early_seconds' => 0,
        'default_end_late_seconds' => 0,
    ]);

    $this->auth = PlaylistAuth::create([
        'name' => 'Credential A',
        'username' => 'credential-a',
        'password' => 'password-a',
        'enabled' => true,
        'dvr_enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach([$this->auth->id]);

    // Both EPGs carry "Channel4.uk"; the playlist channel is mapped to EPG B only.
    $this->epgA = Epg::factory()->for($this->user)->create(['name' => 'Country guide']);
    $this->epgB = Epg::factory()->for($this->user)->create(['name' => 'Custom guide']);

    $this->epgChannelA = EpgChannel::factory()->for($this->epgA)->create(['user_id' => $this->user->id, 'channel_id' => 'Channel4.uk', 'name' => 'Channel 4 (A)', 'display_name' => 'Channel 4 (A)']);
    $this->epgChannelB = EpgChannel::factory()->for($this->epgB)->create(['user_id' => $this->user->id, 'channel_id' => 'Channel4.uk', 'name' => 'Channel 4 (B)', 'display_name' => 'Channel 4 (B)']);

    $this->channel = Channel::factory()->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'title' => 'Channel 4',
        'stream_id' => 'Channel4.uk',
        'epg_channel_id' => $this->epgChannelB->id,
    ]);

    // Same slot, different titles - as in the report.
    $this->slot = now()->addMinutes(10);
    $this->programmeA = EpgProgramme::factory()->for($this->epgA)->create([
        'epg_channel_id' => 'Channel4.uk',
        'title' => 'Bake Off: Second Helpings',
        'season' => 1,
        'episode' => 8,
        'start_time' => $this->slot,
        'end_time' => $this->slot->copy()->addHour(),
    ]);
    $this->programmeB = EpgProgramme::factory()->for($this->epgB)->create([
        'epg_channel_id' => 'Channel4.uk',
        'title' => 'Bake Off',
        'season' => 14,
        'episode' => 3,
        'start_time' => $this->slot,
        'end_time' => $this->slot->copy()->addHour(),
    ]);
});

it('only returns the mapped EPG\'s programmes from search_epg_shows', function () {
    $response = $this->postJson(route('xtream.api.player').'?'.http_build_query([
        'username' => 'credential-a',
        'password' => 'password-a',
        'action' => 'search_epg_shows',
        'q' => 'bake off',
    ]))->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.display_title'))->toBe('Bake Off')
        ->and($response->json('0.recent_episodes'))->toHaveCount(1)
        ->and($response->json('0.recent_episodes.0.season'))->toBe(14)
        ->and($response->json('0.recent_episodes.0.channel_id'))->toBe($this->channel->id)
        ->and($response->json('0.recent_episodes.0.channel_name'))->toBe('Channel 4');
});

it('keeps channels mapped to different EPGs apart in search_epg_shows', function () {
    $channelA = Channel::factory()->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'title' => 'Channel 4 Country',
        'epg_channel_id' => $this->epgChannelA->id,
    ]);

    $response = $this->postJson(route('xtream.api.player').'?'.http_build_query([
        'username' => 'credential-a',
        'password' => 'password-a',
        'action' => 'search_epg_shows',
        'q' => 'bake off',
    ]))->assertOk();

    $episodesByTitle = collect($response->json())
        ->flatMap(fn (array $show) => $show['recent_episodes'])
        ->keyBy('title');

    expect($episodesByTitle)->toHaveCount(2)
        ->and($episodesByTitle['Bake Off']['channel_id'])->toBe($this->channel->id)
        ->and($episodesByTitle['Bake Off: Second Helpings']['channel_id'])->toBe($channelA->id);
});

it('schedules only the mapped EPG\'s airing for a channel-pinned series rule', function () {
    $rule = DvrRecordingRule::factory()->series()->for($this->setting, 'dvrSetting')->for($this->user)->create([
        'series_title' => 'Bake Off',
        'channel_id' => $this->channel->id,
    ]);

    app(DvrSchedulerService::class)->matchAndSchedule(30);

    $recordings = DvrRecording::where('dvr_recording_rule_id', $rule->id)->get();

    expect($recordings)->toHaveCount(1)
        ->and($recordings->first()->status)->toBe(DvrRecordingStatus::Scheduled)
        ->and($recordings->first()->title)->toBe('Bake Off');
});

it('schedules only mapped EPGs\' airings for an any-channel series rule', function () {
    $rule = DvrRecordingRule::factory()->series()->for($this->setting, 'dvrSetting')->for($this->user)->create([
        'series_title' => 'Bake Off',
    ]);

    $result = app(DvrSchedulerService::class)->matchSeriesRuleDryRun($rule, 30);

    expect($result['scheduled'])->toBe([$this->programmeB->id]);
});

it('scopes an explicit EPG channel rule to that EPG', function () {
    $rule = DvrRecordingRule::factory()->series()->for($this->setting, 'dvrSetting')->for($this->user)->create([
        'series_title' => 'Bake Off',
        'epg_channel_id' => $this->epgChannelA->id,
    ]);

    $result = app(DvrSchedulerService::class)->matchSeriesRuleDryRun($rule, 30);

    expect($result['scheduled'])->toBe([$this->programmeA->id]);
});

it('only previews the mapped EPG\'s airings for a series rule', function () {
    $rule = DvrRecordingRule::factory()->series()->for($this->setting, 'dvrSetting')->for($this->user)->create([
        'series_title' => 'Bake Off',
        'channel_id' => $this->channel->id,
    ]);

    $airings = (fn () => static::resolveMatchedAirings($rule))
        ->bindTo(null, DvrRecordingRuleResource::class)();

    expect($airings)->toHaveCount(1)
        ->and($airings[0]['season'])->toBe(14)
        ->and($airings[0]['channel_name'])->toBe('Channel 4 (B)');
});

it('only lists the mapped EPG\'s programmes in Browse Shows', function () {
    Livewire::actingAs($this->user)
        ->test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Bake Off')
        ->call('search')
        ->assertSet('shows', fn (array $shows) => collect($shows)->pluck('title')->all() === ['Bake Off']);
});

it('still matches unmapped channels by tvg-id in Browse Shows', function () {
    $this->channel->update(['epg_channel_id' => null]);

    Livewire::actingAs($this->user)
        ->test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Bake Off')
        ->call('search')
        ->assertSet('shows', fn (array $shows) => collect($shows)->pluck('title')->sort()->values()->all() === ['Bake Off', 'Bake Off: Second Helpings']);
});
