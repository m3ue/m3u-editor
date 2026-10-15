<?php

/**
 * Over-the-air sub-channel numbers ("5.1", "5.2", "5.10"), issue #1604.
 *
 * Verifies that:
 * - Numbers are parsed/formatted as a [major, minor] pair, keeping "5.1" and "5.10" distinct
 * - A custom playlist pivot override brings its own minor (a "2.1" channel overridden to "7" is 7)
 * - M3U, HDHR and EPG output the full number; Xtream `num` stays the integer major
 * - Ordering puts sub-channels after their major number, on every driver's NULL ordering
 * - Recounts clear the minor so a renumbered "2.1" doesn't become "5.1"
 * - The M3U (tvg-chno) and Xtream (num) imports keep sub-channels instead of truncating them
 * - The channel form, table columns and REST API accept and store the pair
 */

use App\Enums\PlaylistChannelId;
use App\Facades\SortFacade;
use App\Filament\Resources\Channels\Pages\EditChannel;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Filament\Resources\CustomPlaylists\Pages\EditCustomPlaylist;
use App\Filament\Resources\CustomPlaylists\RelationManagers\ChannelsRelationManager;
use App\Http\Controllers\PlaylistGenerateController;
use App\Jobs\ProcessM3uImport;
use App\Jobs\ProcessM3uImportChunk;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use App\Support\ChannelNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 1]);
    $this->customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    $this->makeChannel = fn (string $title, ?int $number, ?int $minor = null, int $sort = 1) => Channel::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->for($this->group)
        ->create([
            'enabled' => true,
            'is_vod' => false,
            'channel' => $number,
            'channel_minor' => $minor,
            'sort' => $sort,
            'title' => $title,
            'url' => 'http://example.com/stream/'.str_replace('.', '-', $title),
        ]);

    Storage::disk('local')->deleteDirectory('playlist-epg-files');
});

// ---------------------------------------------------------------------------
// ChannelNumber parsing and formatting
// ---------------------------------------------------------------------------

it('parses channel numbers into a major and minor pair', function (int|float|string|null $input, array $expected) {
    expect(ChannelNumber::parse($input))->toBe($expected);
})->with([
    'whole number' => ['7', [7, null]],
    'int' => [7, [7, null]],
    'sub-channel' => ['5.1', [5, 1]],
    'two-digit sub-channel' => ['5.10', [5, 10]],
    'zero minor' => ['5.0', [5, null]],
    'empty' => ['', [null, null]],
    'null' => [null, [null, null]],
    'surrounding whitespace' => [' 12.3 ', [12, 3]],
    'float from a JSON-decoded Xtream num' => [5.1, [5, 1]],
    'integral float' => [5.0, [5, null]],
    'largest kept minor' => ['5.9999', [5, 9999]],
    'minor past the column range' => ['5.40000', [5, null]],
]);

it('formats a pair as an int without a minor and as major.minor with one', function () {
    expect(ChannelNumber::format(5, null))->toBe(5)
        ->and(ChannelNumber::format(5, 1))->toBe('5.1')
        ->and(ChannelNumber::format(5, 10))->toBe('5.10')
        ->and(ChannelNumber::format('5', '2'))->toBe('5.2')
        ->and(ChannelNumber::format(null, 3))->toBeNull();
});

it('resolves a pivot override as a pair, never borrowing the channel minor', function () {
    expect(ChannelNumber::resolve(2, 1))->toBe('2.1')
        ->and(ChannelNumber::resolve(2, 1, 7, null))->toBe(7)
        ->and(ChannelNumber::resolve(2, 1, 7, 2))->toBe('7.2')
        ->and(ChannelNumber::resolve(2, 1, null, null))->toBe('2.1')
        ->and(ChannelNumber::resolve(2, 1, 0, 5))->toBe('2.1');
});

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

it('outputs the full sub-channel number as tvg-chno in the M3U', function () {
    ($this->makeChannel)('Sub', 5, 1);
    ($this->makeChannel)('Whole', 6, null, 2);

    $content = $this->get("/{$this->playlist->uuid}/playlist.m3u")->assertOk()->streamedContent();

    expect($content)->toContain('tvg-chno="5.1"')
        ->and($content)->toContain('tvg-chno="6"');
});

it('outputs the custom playlist pivot number as a pair', function () {
    $overridden = ($this->makeChannel)('Overridden', 2, 1);
    $overriddenSub = ($this->makeChannel)('OverriddenSub', 3, 1, 2);
    $inherited = ($this->makeChannel)('Inherited', 4, 1, 3);

    $this->customPlaylist->channels()->attach([
        $overridden->id => ['channel_number' => 7, 'channel_minor' => null],
        $overriddenSub->id => ['channel_number' => 8, 'channel_minor' => 2],
        $inherited->id => ['channel_number' => null, 'channel_minor' => null],
    ]);

    $content = $this->get("/{$this->customPlaylist->uuid}/playlist.m3u")->assertOk()->streamedContent();

    expect($content)->toContain('tvg-chno="7"')
        ->and($content)->not->toContain('tvg-chno="7.1"')
        ->and($content)->toContain('tvg-chno="8.2"')
        ->and($content)->toContain('tvg-chno="4.1"');
});

it('keeps Xtream num as the integer major number', function () {
    $this->playlist->update(['id_channel_by' => PlaylistChannelId::Number]);
    ($this->makeChannel)('Sub', 5, 1);

    $streams = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_live_streams')
        ->assertOk()
        ->json();

    expect($streams[0]['num'])->toBe(5)
        ->and($streams[0]['epg_channel_id'])->toBe('5.1');
});

it('uses the full sub-channel number for HDHR GuideNumber and EPG ids when identifying by number', function () {
    Storage::fake('local');
    $this->playlist->update(['id_channel_by' => PlaylistChannelId::Number, 'dummy_epg' => true]);
    ($this->makeChannel)('Sub', 5, 1);
    ($this->makeChannel)('Sibling', 5, 2, 2);

    $lineup = json_decode($this->get("/{$this->playlist->uuid}/hdhr/lineup.json")->assertOk()->streamedContent(), true);

    expect(array_column($lineup, 'GuideNumber'))->toBe(['5.1', '5.2']);

    $xml = gzdecode($this->get("/{$this->playlist->uuid}/epg.xml.gz")->assertOk()->streamedContent());

    expect($xml)->toContain('<channel id="5.1">')
        ->and($xml)->toContain('<channel id="5.2">');
});

// ---------------------------------------------------------------------------
// Ordering
// ---------------------------------------------------------------------------

it('orders sub-channels after their major number when sorting by channel number', function () {
    $this->playlist->update(['sort_by_channel_number' => true]);
    ($this->makeChannel)('5.10', 5, 10, 1);
    ($this->makeChannel)('6', 6, null, 2);
    ($this->makeChannel)('5.2', 5, 2, 3);
    ($this->makeChannel)('5', 5, null, 4);
    ($this->makeChannel)('5.1', 5, 1, 5);

    $titles = PlaylistGenerateController::getChannelQuery($this->playlist)->pluck('title')->all();

    expect($titles)->toBe(['5', '5.1', '5.2', '5.10', '6']);
});

it('orders custom playlist sub-channels by the pivot pair when it overrides', function () {
    $this->customPlaylist->update(['sort_by_channel_number' => true]);
    $first = ($this->makeChannel)('Pivot 9.1', 2, 5);
    $second = ($this->makeChannel)('Own 9.2', 9, 2, 2);
    $third = ($this->makeChannel)('Pivot 9', 1, 3, 3);

    $this->customPlaylist->channels()->attach([
        $first->id => ['channel_number' => 9, 'channel_minor' => 1],
        $second->id => ['channel_number' => null, 'channel_minor' => null],
        $third->id => ['channel_number' => 9, 'channel_minor' => null],
    ]);

    $titles = PlaylistGenerateController::getChannelQuery($this->customPlaylist)->pluck('title')->all();

    expect($titles)->toBe(['Pivot 9', 'Pivot 9.1', 'Own 9.2']);
});

// ---------------------------------------------------------------------------
// Recounts
// ---------------------------------------------------------------------------

it('clears the minor when a group is recounted', function () {
    $channel = ($this->makeChannel)('Sub', 2, 1);

    SortFacade::bulkRecountGroupChannels($this->group, 5);

    expect($channel->fresh()->only(['channel', 'channel_minor']))->toBe(['channel' => 5, 'channel_minor' => null]);
});

it('clears the minor when selected channels are recounted', function () {
    $channel = ($this->makeChannel)('Sub', 2, 1);

    SortFacade::bulkRecountChannels(Channel::whereKey($channel->id)->get(), 5);

    expect($channel->fresh()->only(['channel', 'channel_minor']))->toBe(['channel' => 5, 'channel_minor' => null]);
});

it('clears the pivot minor when custom playlist channels are recounted', function () {
    $channel = ($this->makeChannel)('Sub', 2, 1);
    $this->customPlaylist->channels()->attach($channel->id, ['channel_number' => 2, 'channel_minor' => 1, 'sort' => 1]);

    SortFacade::bulkRecountCustomPlaylistChannels($this->customPlaylist, Channel::whereKey($channel->id)->get(), 5);

    $pivot = DB::table('channel_custom_playlist')->where('channel_id', $channel->id)->first();

    expect((int) $pivot->channel_number)->toBe(5)
        ->and($pivot->channel_minor)->toBeNull()
        // The channel's own number is untouched by a custom playlist recount
        ->and($channel->fresh()->channel_minor)->toBe(1);
});

// ---------------------------------------------------------------------------
// Import
// ---------------------------------------------------------------------------

it('imports tvg-chno sub-channels instead of truncating them', function () {
    $tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($tempJobsDb);
    config(['database.connections.jobs.database' => $tempJobsDb]);
    DB::purge('jobs');
    (require database_path('migrations/2025_02_13_215803_create_jobs_table.php'))->up();

    $m3uPath = sys_get_temp_dir().'/playlist_subchannels_'.uniqid().'.m3u';
    file_put_contents($m3uPath, implode("\n", [
        '#EXTM3U',
        '#EXTINF:-1 tvg-id="a" tvg-chno="5.1" group-title="OTA",Main',
        'http://example.test/stream/1',
        '#EXTINF:-1 tvg-id="b" tvg-chno="5.10" group-title="OTA",Tenth',
        'http://example.test/stream/2',
        '#EXTINF:-1 tvg-id="c" tvg-chno="12" group-title="OTA",Whole',
        'http://example.test/stream/3',
        '#EXTINF:-1 tvg-id="d" tvg-chno="" group-title="OTA",Empty',
        'http://example.test/stream/4',
        '#EXTINF:-1 tvg-id="e" tvg-chno="7.40000" group-title="OTA",Malformed',
        'http://example.test/stream/5',
    ]));

    try {
        $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
            'url' => $m3uPath,
            'xtream' => false,
            'import_prefs' => [],
            'auto_sort' => false,
        ]));

        Bus::fake();
        (new ProcessM3uImport($playlist, force: true, isNew: true))->handle();
        (new ProcessM3uImportChunk(Job::pluck('id')->all(), batchCount: 1))->handle();

        $numbers = $playlist->channels()->get()
            ->mapWithKeys(fn (Channel $channel) => [$channel->title => [$channel->channel, $channel->channel_minor]])
            ->all();

        expect($numbers)->toBe([
            'Main' => [5, 1],
            'Tenth' => [5, 10],
            'Whole' => [12, null],
            'Empty' => [0, null],
            'Malformed' => [7, null],
        ]);
    } finally {
        DB::purge('jobs');
        config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);
        @unlink($tempJobsDb);
        @unlink($m3uPath);
    }
});

it('imports Xtream num sub-channels the same way', function () {
    $tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($tempJobsDb);
    config(['database.connections.jobs.database' => $tempJobsDb]);
    DB::purge('jobs');
    (require database_path('migrations/2025_02_13_215803_create_jobs_table.php'))->up();

    $streams = [
        ['stream_id' => 1, 'name' => 'Whole', 'category_id' => '1', 'num' => 12],
        ['stream_id' => 2, 'name' => 'String', 'category_id' => '1', 'num' => '5.1'],
        ['stream_id' => 3, 'name' => 'Float', 'category_id' => '1', 'num' => 7.2],
        ['stream_id' => 4, 'name' => 'Missing', 'category_id' => '1'],
    ];

    try {
        $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://xtream.example.test:8080',
                'username' => 'user',
                'password' => 'pass',
                'output' => 'ts',
                'import_options' => ['live'],
            ],
            'import_prefs' => [],
        ]));

        Http::fake([
            'http://xtream.example.test:8080/player_api.php*' => function ($request) use ($streams) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

                return match ($query['action'] ?? null) {
                    'get_live_streams' => Http::response(json_encode($streams), 200),
                    'get_live_categories' => Http::response(json_encode([
                        ['category_id' => '1', 'category_name' => 'OTA'],
                    ]), 200),
                    default => Http::response(json_encode(['user_info' => ['status' => 'Active']]), 200),
                };
            },
        ]);

        Bus::fake();
        (new ProcessM3uImport($playlist, force: true, isNew: true))->handle();

        $numbers = collect(Job::firstOrFail()->payload)
            ->mapWithKeys(fn (array $row) => [$row['title'] => [$row['channel'], $row['channel_minor']]])
            ->all();

        expect($numbers)->toBe([
            'Whole' => [12, null],
            'String' => [5, 1],
            'Float' => [7, 2],
            // A missing num stays null, as it did before
            'Missing' => [null, null],
        ]);
    } finally {
        DB::purge('jobs');
        config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);
        @unlink($tempJobsDb);
    }
});

// ---------------------------------------------------------------------------
// UI
// ---------------------------------------------------------------------------

it('saves a sub-channel entered on the channel edit form', function () {
    $this->actingAs($this->user);
    $channel = ($this->makeChannel)('Sub', 2, 1);

    Livewire::test(EditChannel::class, ['record' => $channel->id])
        ->assertFormSet(['channel' => '2.1'])
        ->fillForm(['channel' => '5.10'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($channel->fresh()->only(['channel', 'channel_minor']))->toBe(['channel' => 5, 'channel_minor' => 10]);

    Livewire::test(EditChannel::class, ['record' => $channel->id])
        ->fillForm(['channel' => '8'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($channel->fresh()->only(['channel', 'channel_minor']))->toBe(['channel' => 8, 'channel_minor' => null]);
});

it('rejects a malformed channel number on the edit form', function () {
    $this->actingAs($this->user);
    $channel = ($this->makeChannel)('Sub', 2, 1);

    Livewire::test(EditChannel::class, ['record' => $channel->id])
        ->fillForm(['channel' => '5.1.2'])
        ->call('save')
        ->assertHasFormErrors(['channel' => 'regex']);

    expect($channel->fresh()->only(['channel', 'channel_minor']))->toBe(['channel' => 2, 'channel_minor' => 1]);
});

it('saves a sub-channel from the channels table column', function () {
    $this->actingAs($this->user);
    $channel = ($this->makeChannel)('Sub', 2, null);

    Livewire::test(ListChannels::class)
        ->call('updateTableColumnState', 'channel', (string) $channel->id, '5.1');

    expect($channel->fresh()->only(['channel', 'channel_minor']))->toBe(['channel' => 5, 'channel_minor' => 1]);
});

it('saves a sub-channel from the custom playlist channel number column', function () {
    $this->actingAs($this->user);
    $channel = ($this->makeChannel)('Sub', 2, 1);
    $this->customPlaylist->channels()->attach($channel->id, ['sort' => 1]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $this->customPlaylist,
        'pageClass' => EditCustomPlaylist::class,
    ])->call('updateTableColumnState', 'custom_channel_number', (string) $channel->id, '7.2');

    $pivot = DB::table('channel_custom_playlist')->where('channel_id', $channel->id)->first();

    expect((int) $pivot->channel_number)->toBe(7)
        ->and((int) $pivot->channel_minor)->toBe(2);
});

// ---------------------------------------------------------------------------
// REST API
// ---------------------------------------------------------------------------

it('sets and clears the minor through the channel API', function () {
    $token = $this->user->createToken('test')->plainTextToken;
    $channel = ($this->makeChannel)('Sub', 2, null);

    $this->withToken($token)
        ->patchJson("/channel/{$channel->id}", ['channel_number' => 5, 'channel_minor' => 1])
        ->assertOk()
        ->assertJsonPath('data.channel_number', 5)
        ->assertJsonPath('data.channel_minor', 1);

    // A new number without a minor replaces the whole number
    $this->withToken($token)
        ->patchJson("/channel/{$channel->id}", ['channel_number' => 6])
        ->assertOk()
        ->assertJsonPath('data.channel_minor', null);

    expect($channel->fresh()->only(['channel', 'channel_minor']))->toBe(['channel' => 6, 'channel_minor' => null]);
});

it('sets the pivot minor through the custom playlist API', function () {
    $token = $this->user->createToken('test')->plainTextToken;
    $channel = ($this->makeChannel)('Sub', 2, null);
    $this->customPlaylist->channels()->attach($channel->id);

    $this->withToken($token)
        ->patchJson("/custom-playlist/{$this->customPlaylist->uuid}/channels/{$channel->id}", [
            'channel_number' => 7,
            'channel_minor' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('data.channel_number', 7)
        ->assertJsonPath('data.channel_minor', 2);

    $this->withToken($token)
        ->patchJson("/custom-playlist/{$this->customPlaylist->uuid}/channels/{$channel->id}", ['channel_number' => 8])
        ->assertOk()
        ->assertJsonPath('data.channel_minor', null);
});
