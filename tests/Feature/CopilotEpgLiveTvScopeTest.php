<?php

use App\Filament\CopilotTools\EpgChannelMatcherTool;
use App\Filament\CopilotTools\EpgMappingApplyTool;
use App\Filament\CopilotTools\EpgMappingStateTool;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()
        ->for($this->user)
        ->create(['name' => 'Live TV Playlist']));
    $this->epg = Epg::withoutEvents(fn () => Epg::factory()
        ->for($this->user)
        ->create(['name' => 'Guide Source']));
    $this->epgChannel = EpgChannel::factory()
        ->for($this->epg)
        ->for($this->user)
        ->create([
            'name' => 'Guide Station',
            'display_name' => 'Guide Station',
            'channel_id' => 'guide-station',
        ]);
});

function copilotEpgGroup(string $name): Group
{
    return Group::factory()
        ->for(test()->playlist)
        ->for(test()->user)
        ->create(['name' => $name]);
}

function copilotEpgChannel(Group $group, string $name, array $attributes = []): Channel
{
    return Channel::factory()
        ->for(test()->playlist)
        ->for(test()->user)
        ->for($group)
        ->create(array_merge([
            'name' => $name,
            'title' => $name,
            'stream_id' => str($name)->slug(),
            'group' => $group->name,
            'is_vod' => false,
            'epg_map_enabled' => true,
            'epg_channel_id' => null,
        ], $attributes));
}

it('lists only eligible live TV channels in playlist mapping totals', function () {
    $group = copilotEpgGroup('Mixed Group');

    copilotEpgChannel($group, 'Mapped Live', ['epg_channel_id' => $this->epgChannel->id]);
    copilotEpgChannel($group, 'Unmapped Live');
    copilotEpgChannel($group, 'Mapped VOD', ['is_vod' => true, 'epg_channel_id' => $this->epgChannel->id]);
    copilotEpgChannel($group, 'Unmapped VOD', ['is_vod' => true]);
    copilotEpgChannel($group, 'Disabled Live', ['epg_map_enabled' => false]);

    $output = (string) (new EpgMappingStateTool)->handle(new Request([]));

    expect($output)->toContain("#{$this->playlist->id} Live TV Playlist")
        ->toContain('1/2 mapped, 1 unmapped')
        ->not->toContain('2/5 mapped, 3 unmapped');
});

it('uses only eligible live TV channels in group and total rows', function () {
    $liveGroup = copilotEpgGroup('Live Group');
    $secondLiveGroup = copilotEpgGroup('Second Live');
    $vodGroup = copilotEpgGroup('VOD Only');
    $disabledGroup = copilotEpgGroup('Disabled Only');

    copilotEpgChannel($liveGroup, 'Mapped Live', ['epg_channel_id' => $this->epgChannel->id]);
    copilotEpgChannel($liveGroup, 'Unmapped Live');
    copilotEpgChannel($secondLiveGroup, 'Another Live');
    copilotEpgChannel($vodGroup, 'Movie One', ['is_vod' => true]);
    copilotEpgChannel($disabledGroup, 'Opted Out', ['epg_map_enabled' => false]);

    $output = (string) (new EpgMappingStateTool)->handle(new Request([
        'playlist_id' => $this->playlist->id,
    ]));

    expect($output)->toMatch('/Live Group\s+1\s+1\s+2/')
        ->toMatch('/Second Live\s+0\s+1\s+1/')
        ->toMatch('/TOTAL\s+1\s+2\s+3/')
        ->not->toContain('VOD Only')
        ->not->toContain('Disabled Only');
});

it('reports when a playlist or requested group has no eligible live TV channels', function () {
    $vodGroup = copilotEpgGroup('VOD Only');
    $disabledGroup = copilotEpgGroup('Disabled Only');

    copilotEpgChannel($vodGroup, 'Movie One', ['is_vod' => true]);
    copilotEpgChannel($disabledGroup, 'Opted Out', ['epg_map_enabled' => false]);

    $stateTool = new EpgMappingStateTool;
    $playlistOutput = (string) $stateTool->handle(new Request([
        'playlist_id' => $this->playlist->id,
    ]));
    $vodOutput = (string) $stateTool->handle(new Request([
        'playlist_id' => $this->playlist->id,
        'group' => $vodGroup->name,
    ]));
    $disabledOutput = (string) $stateTool->handle(new Request([
        'playlist_id' => $this->playlist->id,
        'group' => $disabledGroup->name,
    ]));

    expect($playlistOutput)->toContain('No eligible live TV channels found')
        ->and($vodOutput)->toContain('No eligible live TV channels found')
        ->and($disabledOutput)->toContain('No eligible live TV channels found');
});

it('uses the same live TV eligibility predicate for matcher totals and pagination', function () {
    $group = copilotEpgGroup('Mixed Group');
    $eligibleFirst = copilotEpgChannel($group, 'Eligible 01');
    $eligibleSecond = copilotEpgChannel($group, 'Eligible 02');
    $eligibleThird = copilotEpgChannel($group, 'Eligible 03');
    $vod = copilotEpgChannel($group, 'Eligible 00 VOD', ['is_vod' => true]);
    $disabled = copilotEpgChannel($group, 'Eligible 015 Disabled', ['epg_map_enabled' => false]);

    $output = (string) (new EpgChannelMatcherTool)->handle(new Request([
        'playlist_id' => $this->playlist->id,
        'group' => $group->name,
        'epg_id' => $this->epg->id,
        'limit' => 2,
        'offset' => 1,
    ]));

    expect($output)->toMatch('/Channels 2\D+3 of 3 unmapped/')
        ->toContain('page 1/2')
        ->toContain("Channel #{$eligibleSecond->id} \"")
        ->toContain("Channel #{$eligibleThird->id} \"")
        ->not->toContain("Channel #{$eligibleFirst->id} \"")
        ->not->toContain("Channel #{$vod->id} \"")
        ->not->toContain("Channel #{$disabled->id} \"");
});

it('reports when a group has no eligible live TV channels at all', function () {
    $group = copilotEpgGroup('Ineligible Group');

    copilotEpgChannel($group, 'Movie One', ['is_vod' => true]);
    copilotEpgChannel($group, 'Opted Out', ['epg_map_enabled' => false]);

    $output = (string) (new EpgChannelMatcherTool)->handle(new Request([
        'playlist_id' => $this->playlist->id,
        'group' => $group->name,
        'epg_id' => $this->epg->id,
    ]));

    expect($output)->toContain('No eligible live TV channels in group');
});

it('reports when all eligible live TV channels in a group are already mapped', function () {
    $group = copilotEpgGroup('Fully Mapped Group');

    copilotEpgChannel($group, 'Already Mapped', ['epg_channel_id' => $this->epgChannel->id]);

    $output = (string) (new EpgChannelMatcherTool)->handle(new Request([
        'playlist_id' => $this->playlist->id,
        'group' => $group->name,
        'epg_id' => $this->epg->id,
    ]));

    expect($output)->toContain('already mapped');
});

it('honors the playlist\'s saved EPG map settings instead of hardcoded matcher defaults', function () {
    $group = copilotEpgGroup('Prefix Group');
    $channel = copilotEpgChannel($group, 'XX: Sports One');
    $target = EpgChannel::factory()
        ->for($this->epg)
        ->for($this->user)
        ->create([
            'name' => 'Sports One',
            'display_name' => 'Sports One',
            'channel_id' => 'sports-one',
        ]);

    // Without a saved EpgMap, the literal "XX:" prefix is not stripped, so
    // this only surfaces as a fuzzy candidate rather than an automatic match.
    $withoutSettings = (string) (new EpgChannelMatcherTool)->handle(new Request([
        'playlist_id' => $this->playlist->id,
        'group' => $group->name,
        'epg_id' => $this->epg->id,
    ]));

    expect($withoutSettings)->not->toContain('EXACT MATCHES')
        ->toContain("epg_channel_id: {$target->id}");

    // The mapping job would strip "XX: " per this map's saved settings before
    // scoring; the Copilot tool must use the same settings, not its own
    // hardcoded defaults, so its preview matches what apply would actually do.
    EpgMap::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'epg_id' => $this->epg->id,
        'settings' => ['exclude_prefixes' => ['XX: ']],
    ]);

    $withSettings = (string) (new EpgChannelMatcherTool)->handle(new Request([
        'playlist_id' => $this->playlist->id,
        'group' => $group->name,
        'epg_id' => $this->epg->id,
    ]));

    expect($withSettings)->toContain('EXACT MATCHES')
        ->toContain("epg_channel_id: {$target->id}");
});

it('applies mappings only to eligible live TV channels', function () {
    $group = copilotEpgGroup('Mixed Group');
    $eligible = copilotEpgChannel($group, 'Eligible Live');
    $vod = copilotEpgChannel($group, 'Movie One', ['is_vod' => true]);
    $disabled = copilotEpgChannel($group, 'Opted Out', ['epg_map_enabled' => false]);

    $output = (string) (new EpgMappingApplyTool)->handle(new Request([
        'epg_id' => $this->epg->id,
        'mappings' => json_encode([
            ['channel_id' => $eligible->id, 'epg_channel_id' => $this->epgChannel->id],
            ['channel_id' => $vod->id, 'epg_channel_id' => $this->epgChannel->id],
            ['channel_id' => $disabled->id, 'epg_channel_id' => $this->epgChannel->id],
        ], JSON_THROW_ON_ERROR),
    ]));

    expect($output)->toContain('Applied 1 mapping(s) successfully.')
        ->and($eligible->refresh()->epg_channel_id)->toBe($this->epgChannel->id)
        ->and($vod->refresh()->epg_channel_id)->toBeNull()
        ->and($disabled->refresh()->epg_channel_id)->toBeNull();
});
