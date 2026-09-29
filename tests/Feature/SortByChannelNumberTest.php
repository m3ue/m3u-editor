<?php

/**
 * Tests for the optional "Sort by channel number" playlist output ordering.
 *
 * Verifies that:
 * - Default ordering (group, sort, channel, title) is unchanged when the option is off
 * - Enabling the option orders channels flat by channel number across groups
 * - Channels without a number (NULL or 0) are output last, in standard group order
 * - Custom playlists sort by the pivot channel_number, falling back to the channel's own
 * - Aliases inherit the option from their effective playlist
 * - Xtream get_live_streams follows the same ordering
 * - Forced numbering renumbers in the sorted order, on every output (incl. EPG)
 * - Filtered/paginated listings (Xtream category, EPG viewer page) keep the full output's numbers
 * - The cached EPG is only cleared when a numbering change alters number-based tvg-ids
 */

use App\Enums\PlaylistChannelId;
use App\Http\Controllers\PlaylistGenerateController;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use App\Services\EpgCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->groupA = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 1]);
    $this->groupB = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 2]);

    $this->makeChannel = fn (Group $group, ?int $number, int $sort, string $title) => Channel::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->for($group)
        ->create([
            'enabled' => true,
            'is_vod' => false,
            'channel' => $number,
            'sort' => $sort,
            'title' => $title,
        ]);

    // Group A comes first by group order, but holds the higher channel numbers.
    ($this->makeChannel)($this->groupA, 30, 1, 'A30');
    ($this->makeChannel)($this->groupA, null, 2, 'A-none');
    ($this->makeChannel)($this->groupA, 10, 3, 'A10');
    ($this->makeChannel)($this->groupB, 0, 1, 'B-zero');
    ($this->makeChannel)($this->groupB, 20, 2, 'B20');
    ($this->makeChannel)($this->groupB, 5, 3, 'B5');
});

it('keeps the standard group ordering when sort by channel number is off', function () {
    $titles = PlaylistGenerateController::getChannelQuery($this->playlist)->pluck('title')->all();

    expect($titles)->toBe(['A30', 'A-none', 'A10', 'B-zero', 'B20', 'B5']);
});

it('orders channels flat by channel number with unnumbered channels last', function () {
    $this->playlist->update(['sort_by_channel_number' => true]);

    $titles = PlaylistGenerateController::getChannelQuery($this->playlist)->pluck('title')->all();

    expect($titles)->toBe(['B5', 'A10', 'B20', 'A30', 'A-none', 'B-zero']);
});

it('orders custom playlist channels by pivot channel number, falling back to the channel number', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create(['sort_by_channel_number' => true]);
    $channels = Channel::query()->pluck('id', 'title');

    $customPlaylist->channels()->attach([
        $channels['A30'] => ['sort' => 1, 'channel_number' => 1],
        $channels['A10'] => ['sort' => 2, 'channel_number' => 0],
        $channels['B20'] => ['sort' => 3, 'channel_number' => 50],
        $channels['A-none'] => ['sort' => 4, 'channel_number' => null],
    ]);

    $titles = PlaylistGenerateController::getChannelQuery($customPlaylist)->pluck('title')->all();

    expect($titles)->toBe(['A30', 'A10', 'B20', 'A-none']);
});

it('inherits sort by channel number on an alias from its source playlist', function () {
    $this->playlist->update(['sort_by_channel_number' => true]);
    $alias = PlaylistAlias::create([
        'name' => 'Alias',
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    expect($alias->sort_by_channel_number)->toBeTrue();

    $titles = PlaylistGenerateController::getChannelQuery($alias)->pluck('title')->all();

    expect($titles)->toBe(['B5', 'A10', 'B20', 'A30', 'A-none', 'B-zero']);
});

it('orders Xtream get_live_streams by channel number when enabled', function () {
    $this->playlist->update(['sort_by_channel_number' => true]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_live_streams');

    $response->assertStatus(200);

    expect(array_column($response->json(), 'name'))->toBe(['B5', 'A10', 'B20', 'A30', 'A-none', 'B-zero']);
});

/**
 * Xtream get_live_streams `num` values keyed by channel name.
 *
 * @return array<string, int|null>
 */
function xtreamLiveNumbers($test, Playlist $playlist, ?int $categoryId = null): array
{
    $url = '/player_api.php?username='.urlencode($test->user->name).'&password='.urlencode($playlist->uuid).'&action=get_live_streams';
    if ($categoryId !== null) {
        $url .= '&category_id='.$categoryId;
    }

    $streams = $test->getJson($url)->assertStatus(200)->json();

    return array_combine(array_column($streams, 'name'), array_column($streams, 'num'));
}

it('renumbers in channel number order when forced numbering is also on', function () {
    $this->playlist->update(['sort_by_channel_number' => true, 'force_channel_numbering' => true, 'channel_start' => 100]);

    expect(xtreamLiveNumbers($this, $this->playlist))->toBe([
        'B5' => 100, 'A10' => 101, 'B20' => 102, 'A30' => 103, 'A-none' => 104, 'B-zero' => 105,
    ]);
});

it('orders merged playlist channels flat by channel number across source playlists', function () {
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $otherGroup = Group::factory()->for($otherPlaylist)->for($this->user)->create(['sort_order' => 1]);
    foreach ([7 => 'P7', 25 => 'P25'] as $number => $title) {
        Channel::factory()->for($this->user)->for($otherPlaylist)->for($otherGroup)->create([
            'enabled' => true, 'is_vod' => false, 'channel' => $number, 'sort' => 1, 'title' => $title,
        ]);
    }

    $merged = MergedPlaylist::factory()->for($this->user)->create(['sort_by_channel_number' => true]);
    $merged->playlists()->attach($this->playlist->id, ['include_live' => true, 'include_vod' => true, 'include_series' => true]);
    $merged->playlists()->attach($otherPlaylist->id, ['include_live' => true, 'include_vod' => true, 'include_series' => true]);

    $titles = PlaylistGenerateController::getChannelQuery($merged)->pluck('title')->all();

    expect(array_slice($titles, 0, 6))->toBe(['B5', 'P7', 'A10', 'B20', 'P25', 'A30'])
        ->and(array_slice($titles, 6))->toEqualCanonicalizing(['A-none', 'B-zero']);
});

it('keeps the full listing numbers when an Xtream category is requested', function (array $settings, array $expected) {
    $this->playlist->update(['channel_start' => 100, ...$settings]);

    $full = xtreamLiveNumbers($this, $this->playlist);
    $category = xtreamLiveNumbers($this, $this->playlist, $this->groupB->id);

    expect($category)->toBe($expected)
        ->and($category)->toBe(array_intersect_key($full, $category));
})->with([
    'forced numbering' => [['force_channel_numbering' => true], ['B-zero' => 103, 'B20' => 104, 'B5' => 105]],
    'auto increment' => [['auto_channel_increment' => true], ['B-zero' => 101, 'B20' => 20, 'B5' => 5]],
]);

it('keeps the full listing numbers for a custom playlist Xtream category', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create([
        'sort_by_channel_number' => true,
        'auto_channel_increment' => true,
        'channel_start' => 100,
    ]);
    $channels = Channel::query()->pluck('id', 'title');
    $customPlaylist->channels()->attach([
        $channels['A30'] => ['sort' => 1, 'channel_number' => 1],
        $channels['A-none'] => ['sort' => 2, 'channel_number' => null],
        $channels['B-zero'] => ['sort' => 1, 'channel_number' => null],
        $channels['B20'] => ['sort' => 2, 'channel_number' => 50],
    ]);

    $url = '/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($customPlaylist->uuid).'&action=get_live_streams';
    $numbers = fn (string $query = '') => collect($this->getJson($url.$query)->assertStatus(200)->json())->pluck('num', 'name')->all();

    expect($numbers())->toBe(['A30' => 1, 'B20' => 50, 'A-none' => 100, 'B-zero' => 101])
        ->and($numbers('&category_id='.$this->groupB->id))->toBe(['B20' => 50, 'B-zero' => 101]);
});

it('applies forced numbering to the EPG channel ids', function () {
    Storage::fake('local');
    $this->playlist->update([
        'force_channel_numbering' => true,
        'channel_start' => 100,
        'id_channel_by' => PlaylistChannelId::Number,
        'dummy_epg' => true,
    ]);

    // The compressed route buffers its output, so it can be read back here.
    $xml = gzdecode($this->get("/{$this->playlist->uuid}/epg.xml.gz")->assertOk()->getContent());
    preg_match_all('/<channel id="([^"]+)">/', $xml, $matches);

    expect($matches[1])->toBe(['100', '101', '102', '103', '104', '105']);
});

it('keeps the full output numbers on later EPG viewer pages', function () {
    $this->playlist->update(['force_channel_numbering' => true, 'channel_start' => 100]);
    $this->actingAs($this->user);

    $channels = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data?per_page=2&page=2")
        ->assertOk()
        ->json('channels');

    expect(collect($channels)->pluck('channel_number')->values()->all())->toBe([102, 103]);
});

describe('EPG cache clearing on numbering changes', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->alias = PlaylistAlias::create([
            'name' => 'Alias',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
        ]);
        $this->seedEpgCache = function (...$playlists) {
            foreach ($playlists as $playlist) {
                Storage::disk('local')->put(EpgCacheService::getPlaylistEpgCachePath($playlist), '<tv/>');
            }
        };
        $this->hasEpgCache = fn ($playlist) => Storage::disk('local')->exists(EpgCacheService::getPlaylistEpgCachePath($playlist));
    });

    it('clears the playlist and alias caches when forced numbering changes number-based ids', function () {
        $this->playlist->update(['id_channel_by' => PlaylistChannelId::Number]);
        ($this->seedEpgCache)($this->playlist, $this->alias);

        $this->playlist->update(['force_channel_numbering' => true]);

        expect(($this->hasEpgCache)($this->playlist))->toBeFalse()
            ->and(($this->hasEpgCache)($this->alias))->toBeFalse();
    });

    it('clears the cache when sorting changes while forced numbering is on', function () {
        $this->playlist->update(['id_channel_by' => PlaylistChannelId::Number, 'force_channel_numbering' => true]);
        ($this->seedEpgCache)($this->playlist);

        $this->playlist->update(['sort_by_channel_number' => true]);

        expect(($this->hasEpgCache)($this->playlist))->toBeFalse();
    });

    it('keeps the cache when sorting changes without forced numbering', function () {
        $this->playlist->update(['id_channel_by' => PlaylistChannelId::Number]);
        ($this->seedEpgCache)($this->playlist, $this->alias);

        $this->playlist->update(['sort_by_channel_number' => true]);

        expect(($this->hasEpgCache)($this->playlist))->toBeTrue()
            ->and(($this->hasEpgCache)($this->alias))->toBeTrue();
    });

    it('keeps the cache when ids are not number based', function () {
        $this->playlist->update(['id_channel_by' => PlaylistChannelId::TvgId]);
        ($this->seedEpgCache)($this->playlist);

        $this->playlist->update(['force_channel_numbering' => true, 'sort_by_channel_number' => true]);

        expect(($this->hasEpgCache)($this->playlist))->toBeTrue();
    });

    it('clears a custom playlist cache when forced numbering changes number-based ids', function () {
        $customPlaylist = CustomPlaylist::factory()->for($this->user)->create(['id_channel_by' => PlaylistChannelId::Number]);
        ($this->seedEpgCache)($customPlaylist);

        $customPlaylist->update(['force_channel_numbering' => true]);

        expect(($this->hasEpgCache)($customPlaylist))->toBeFalse();
    });
});
