<?php

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'dummy_epg' => true,
        'dummy_epg_length' => 120,
        'dummy_epg_category' => true,
    ]);
    $this->actingAs($this->user);
});

it('can get epg data for playlist without epg mapping', function () {
    // Create a group
    $group = Group::factory()->create([
        'name' => 'Test Group',
        'user_id' => $this->user->id,
    ]);

    // Create channels without EPG mapping (dummy EPG should be generated)
    // Explicitly set channel field to predictable values
    $channels = collect();
    for ($i = 1; $i <= 3; $i++) {
        $channels->push(Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'group' => 'Test Group', // Also set the string group field for dummy EPG category
            'enabled' => true,
            'is_vod' => false,
            'channel' => 100 + $i, // Predictable channel numbers
        ]));
    }

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

    $response->assertSuccessful()
        ->assertJsonStructure([
            'playlist' => ['id', 'name', 'uuid', 'type'],
            'date_range' => ['start', 'end'],
            'pagination',
            'channels',
            'programmes',
            'cache_info',
        ]);

    // Verify dummy EPG programmes were generated for channels without EPG
    $data = $response->json();
    $this->assertNotEmpty($data['programmes'], 'Dummy EPG programmes should be generated');

    // Check that programmes were generated for each channel
    foreach ($channels as $channel) {
        $channel->refresh(); // Refresh to get latest data
        $channelId = $channel->id;
        $this->assertArrayHasKey($channelId, $data['programmes'], "Channel {$channelId} should have programmes");

        $programmes = $data['programmes'][$channelId];
        $this->assertNotEmpty($programmes, 'Programmes should not be empty');

        // Verify programme structure
        $firstProgramme = $programmes[0];
        $this->assertArrayHasKey('start', $firstProgramme);
        $this->assertArrayHasKey('stop', $firstProgramme);
        $this->assertArrayHasKey('title', $firstProgramme);
        $this->assertArrayHasKey('desc', $firstProgramme);
        $this->assertArrayHasKey('icon', $firstProgramme);

        // Verify category is included when enabled
        $this->assertArrayHasKey('category', $firstProgramme);
        $this->assertEquals($group->name, $firstProgramme['category']);

        // Verify programme length is correct (120 minutes)
        $start = Carbon::parse($firstProgramme['start']);
        $stop = Carbon::parse($firstProgramme['stop']);
        $this->assertEquals(120, $start->diffInMinutes($stop));
    }
});

it('respects date range for dummy epg', function () {
    // Create a channel without EPG mapping
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'is_vod' => false,
        'channel' => 998, // Explicit channel number
    ]);

    $startDate = Carbon::now()->format('Y-m-d');
    $endDate = Carbon::now()->addDay()->format('Y-m-d');

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data?start_date={$startDate}&end_date={$endDate}");

    $response->assertSuccessful();

    $data = $response->json();
    $channel->refresh(); // Refresh to get latest data
    $channelId = $channel->id;
    $programmes = $data['programmes'][$channelId] ?? [];

    $this->assertNotEmpty($programmes);

    // Verify all programmes fall within the requested date range
    $rangeStart = Carbon::parse($startDate)->startOfDay();
    $rangeEnd = Carbon::parse($endDate)->endOfDay();

    foreach ($programmes as $programme) {
        $programmeStart = Carbon::parse($programme['start']);
        $this->assertGreaterThanOrEqual($rangeStart, $programmeStart);
        $this->assertLessThan($rangeEnd, $programmeStart);
    }
});

it('does not generate dummy epg when disabled', function () {
    // Disable dummy EPG
    $this->playlist->update(['dummy_epg' => false]);

    // Create a channel without EPG mapping
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'is_vod' => false,
        'channel' => 997, // Explicit channel number
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

    $response->assertSuccessful();

    $data = $response->json();
    $channel->refresh(); // Refresh to get latest data
    $channelId = $channel->id;

    // Programmes should be empty or not include the channel without EPG
    $this->assertEmpty($data['programmes'][$channelId] ?? []);
});

it('can disable dummy epg category', function () {
    // Disable category in dummy EPG
    $this->playlist->update(['dummy_epg_category' => false]);

    // Create a group and channel
    $group = Group::factory()->create([
        'name' => 'Test Group',
        'user_id' => $this->user->id,
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
        'channel' => 996, // Explicit channel number
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

    $response->assertSuccessful();

    $data = $response->json();
    $channel->refresh(); // Refresh to get latest data
    $channelId = $channel->id;
    $programmes = $data['programmes'][$channelId] ?? [];

    $this->assertNotEmpty($programmes);

    // Verify category is not included
    $firstProgramme = $programmes[0];
    $this->assertArrayNotHasKey('category', $firstProgramme);
});

it('handles mixed epg and dummy epg channels', function () {
    // Create a group for both channels
    $group = Group::factory()->create([
        'user_id' => $this->user->id,
    ]);

    // Create an EPG
    $epg = Epg::factory()->create([
        'user_id' => $this->user->id,
        'is_cached' => true,
    ]);

    // Create EPG channel
    $epgChannel = EpgChannel::factory()->create([
        'epg_id' => $epg->id,
        'channel_id' => 'test-channel-1',
        'user_id' => $this->user->id,
    ]);

    // Create a channel with EPG mapping
    // Set explicit sort values to ensure deterministic ordering
    $channelWithEpg = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'sort' => 1,
        'channel' => 1,
        'title' => 'Channel A',
    ]);

    // Create a channel without EPG mapping (should get dummy EPG)
    // Set explicit sort values to ensure deterministic ordering
    $channelWithoutEpg = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'channel' => 2,
        'title' => 'Channel B',
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

    $response->assertSuccessful();

    $data = $response->json();

    // Both channels should be in the response
    $this->assertCount(2, $data['channels']);

    // Channel without EPG should have dummy programmes
    $channelWithoutEpg->refresh(); // Refresh to get latest data
    $channelId = $channelWithoutEpg->id;
    $this->assertArrayHasKey($channelId, $data['programmes']);
    $this->assertNotEmpty($data['programmes'][$channelId]);
});

it('respects pagination for dummy epg', function () {
    // Create multiple channels without EPG mapping with unique channel numbers
    $channels = collect();
    for ($i = 1; $i <= 5; $i++) {
        $channels->push(Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'enabled' => true,
            'is_vod' => false,
            'channel' => 900 + $i, // Explicit unique channel numbers
        ]));
    }

    // Request first page with 2 items per page
    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data?per_page=2&page=1");

    $response->assertSuccessful();

    $data = $response->json();

    // Should only have 2 channels on this page
    $this->assertCount(2, $data['channels']);
    $this->assertEquals(2, $data['pagination']['returned_channels']);
    $this->assertEquals(5, $data['pagination']['total_channels']);

    // Verify programmes are only generated for paginated channels
    $this->assertCount(2, $data['programmes']);
});

it('supports a custom dummy epg length', function () {
    // Set custom EPG length to 60 minutes
    $this->playlist->update(['dummy_epg_length' => 60]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'is_vod' => false,
        'channel' => 999, // Explicit channel number to avoid collisions
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

    $response->assertSuccessful();

    $data = $response->json();
    $channel->refresh(); // Refresh to get latest data
    // Always use database ID as channel key (controller guarantees uniqueness)
    $channelId = $channel->id;
    $programmes = $data['programmes'][$channelId] ?? [];

    $this->assertNotEmpty($programmes);

    // Verify programme length is 60 minutes
    $firstProgramme = $programmes[0];
    $start = Carbon::parse($firstProgramme['start']);
    $stop = Carbon::parse($firstProgramme['stop']);
    $this->assertEquals(60, $start->diffInMinutes($stop));
});

it('gets metadata only for unauthenticated request without credentials', function () {
    // No session, no username/password: the response must never fall back to
    // embedding the playlist owner's real Xtream credentials in a channel URL.
    $this->app['auth']->forgetGuards();

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'is_vod' => false,
        'channel' => 995,
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

    $response->assertSuccessful();

    $data = $response->json();
    $this->assertFalse($data['playable']);

    $channelEntry = collect($data['channels'])->firstWhere('id', $channel->id);
    $this->assertNotNull($channelEntry);
    $this->assertFalse($channelEntry['playable']);
    $this->assertNull($channelEntry['url']);
    $this->assertNull($channelEntry['format']);
});

it('gets metadata only for unauthenticated request with foreign playlist credentials', function () {
    // Valid credentials for a *different* playlist must not unlock playable
    // URLs for this playlist.
    $this->app['auth']->forgetGuards();

    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'is_vod' => false,
        'channel' => 994,
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data?".http_build_query([
        'username' => $otherUser->name,
        'password' => $otherPlaylist->uuid,
    ]));

    $response->assertSuccessful();

    $data = $response->json();
    $this->assertFalse($data['playable']);

    $channelEntry = collect($data['channels'])->firstWhere('id', $channel->id);
    $this->assertNull($channelEntry['url']);
});

it('gets playable urls for unauthenticated request with matching playlist auth', function () {
    // Valid PlaylistAuth credentials assigned to *this* playlist should still
    // unlock playable URLs for an unauthenticated (no panel session) caller.
    $this->app['auth']->forgetGuards();

    $playlistAuth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
    ]);
    $playlistAuth->assignTo($this->playlist);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'is_vod' => false,
        'channel' => 993,
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data?".http_build_query([
        'username' => $playlistAuth->username,
        'password' => $playlistAuth->password,
    ]));

    $response->assertSuccessful();

    $data = $response->json();
    $this->assertTrue($data['playable']);

    $channelEntry = collect($data['channels'])->firstWhere('id', $channel->id);
    $this->assertTrue($channelEntry['playable']);
    $this->assertNotEmpty($channelEntry['url']);
    $this->assertStringContainsString((string) $playlistAuth->username, $channelEntry['url']);
});

/*
 * The in-app EPG viewer authenticates via the panel session cookie, so the EPG
 * routes must load the session. They live in routes/api.php, whose `api` group
 * is stateless — without EncryptCookies + StartSession, `Auth::check()` is
 * always false and every channel comes back with a null `url`, which hides the
 * play button in the playlist EPG view.
 *
 * This cannot be covered by a request test: `actingAs()` sets the guard's user
 * directly and passes regardless of which middleware the route declares.
 */
it('loads the panel session on the epg routes', function (string $routeName) {
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull();

    $middleware = app(Router::class)->gatherRouteMiddleware($route);

    expect($middleware)
        ->toContain(EncryptCookies::class)
        ->toContain(StartSession::class);
})->with([
    'api.epg.data',
    'api.epg.playlist.data',
    'api.epg.playlist.groups',
]);
