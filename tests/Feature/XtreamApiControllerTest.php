<?php

use App\Enums\ChannelLogoType;
use App\Jobs\MergeChannels;
use App\Jobs\UnmergeChannels;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    // Create a unique playlist for each test setup to avoid interference
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->username = 'testuser_'.Str::random(5); // Unique username for auth
    $this->password = 'testpass';

    // Create PlaylistAuth and attach it to the playlist using the polymorphic relationship
    $playlistAuth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);

    // Attach the auth to the playlist using the morphToMany relationship
    $this->playlist->playlistAuths()->attach($playlistAuth);
});

// Helper to build URL for Xtream API actions
function getXtreamApiUrl(string $username, string $password, string $action, array $params = []): string
{
    $queryParams = array_merge([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ], $params);

    // Use the correct route name for Xtream API
    return route('xtream.api.player').'?'.http_build_query($queryParams);
}

// Standard setup for authenticated requests to panel action (from existing tests, slightly adapted)
function setupAuthenticatedPanelRequest($test, ?User $user = null, ?Playlist $playlist = null, array $playlistAuthCredentials = [])
{
    $user = $user ?? $test->user; // Use shared state if not provided
    $playlist = $playlist ?? $test->playlist; // Use shared state if not provided

    $authUsername = $playlistAuthCredentials['username'] ?? $test->username;
    $authPassword = $playlistAuthCredentials['password'] ?? $test->password;

    // PlaylistAuth is already created in beforeEach generally, but this allows override for specific panel tests
    $existingAuth = $playlist->playlistAuths->where('username', $authUsername)->first();
    if (! $existingAuth) {
        $playlistAuth = PlaylistAuth::create([
            'name' => 'Test Auth Override',
            'username' => $authUsername,
            'password' => $authPassword,
            'enabled' => true,
            'user_id' => $user->id,
        ]);
        $playlist->playlistAuths()->attach($playlistAuth);
    }

    return $test->getJson(route('xtream.api.player', [
        'action' => 'panel',
        'username' => $authUsername,
        'password' => $authPassword,
    ]));
}

it('returns correct structure for panel action with valid playlist auth', function () {
    $response = setupAuthenticatedPanelRequest($this);

    $response->assertOk();
    $response->assertJsonStructure([
        'user_info',
        'server_info',
    ]);
    $response->assertJsonStructure([
        'user_info' => [
            'username',
            'password',
            'message',
            'auth',
            'status',
            'exp_date',
            'is_trial',
            'active_cons',
            'created_at',
            'max_connections',
            'allowed_output_formats',
        ],
    ]);
    $response->assertJsonPath('user_info.status', 'Active');
    $response->assertJsonStructure([
        'server_info' => [
            'url',
            'port',
            'https_port',
            'rtmp_port',
            'server_protocol',
            'timezone',
            'timestamp_now',
            'time_now',
            'process',
        ],
    ]);
});

it('includes m3u editor payload when enhanced output is enabled for panel action', function () {
    $settings = app(GeneralSettings::class);
    $settings->app_output_enabled = true;
    $settings->save();

    $response = setupAuthenticatedPanelRequest($this);

    $response->assertOk();
    $response->assertJsonStructure([
        'm3u_editor' => [
            'version',
            'features',
        ],
    ]);
});

it('omits m3u editor payload when enhanced output is disabled for panel action', function () {
    $settings = app(GeneralSettings::class);
    $settings->app_output_enabled = false;
    $settings->save();

    $response = setupAuthenticatedPanelRequest($this);

    $response->assertOk();
    $response->assertJsonMissingPath('m3u_editor');
    $response->assertJsonStructure([
        'user_info',
        'server_info',
    ]);
});

it('returns unauthorized for panel action with invalid playlist auth', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    // Create a valid auth and attach it to the playlist
    $playlistAuth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => 'correct_user',
        'password' => 'correct_password',
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($playlistAuth);

    $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'action' => 'panel',
        'username' => 'correct_user',
        'password' => 'incorrect_password', // Using incorrect password
    ]));

    $response->assertStatus(401);
    $response->assertJson(['error' => 'Unauthorized']);
});

/**
 * @skip m3ue authentication is not implemented in current API
 */
it('returns success for panel action with m3ue user and correct password', function () {
    $this->markTestSkipped('m3ue authentication method is not implemented in current API');
});

/**
 * @skip m3ue authentication is not implemented in current API
 */
it('returns unauthorized for panel action with m3ue user and incorrect password', function () {
    $this->markTestSkipped('m3ue authentication method is not implemented in current API');
});

it('returns not found for panel action with non existent playlist uuid', function () {
    $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'action' => 'panel',
        'username' => 'any',
        'password' => 'any',
    ]));
    $response->assertStatus(401);
    $response->assertJson(['error' => 'Unauthorized']);
});

it('returns unauthorized for panel action with missing credentials', function () {
    $playlist = Playlist::factory()->create(); // User automatically created by Playlist factory if not specified

    $responseMissingUser = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'action' => 'panel',
        'password' => 'test',
    ]));
    $responseMissingUser->assertStatus(422); // Validation error for missing username

    $responseMissingPass = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'action' => 'panel',
        'username' => 'test',
    ]));
    $responseMissingPass->assertStatus(422); // Validation error for missing password
});

// Tests for get_live_streams
it('returns live streams successfully', function () {
    $group = Group::factory()->for($this->user)->create(); // Use existing Group model
    $enabledChannel1 = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'title_custom' => 'Enabled Channel 1',
        'logo_type' => ChannelLogoType::Channel,
        'logo' => 'https://example.com/icon1.png',
    ]);
    $enabledChannel2 = Channel::factory()->for($this->playlist)->for($group)->create(['enabled' => true, 'title_custom' => 'Enabled Channel 2']);
    Channel::factory()->for($this->playlist)->create(['enabled' => false, 'title_custom' => 'Disabled Channel']);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_live_streams'));

    $response->assertStatus(200)
        ->assertJsonCount(2)
        ->assertJsonFragment(['name' => 'Enabled Channel 1'])
        ->assertJsonFragment(['name' => 'Enabled Channel 2'])
        ->assertJsonMissing(['name' => 'Disabled Channel']);

    $response->assertJsonStructure([
        '*' => [
            'num',
            'name',
            'stream_type',
            'stream_id',
            'stream_icon',
            'epg_channel_id',
            'added',
            'category_id',
            'tv_archive',
            'direct_source',
            'tv_archive_duration',
        ],
    ]);
    // Find channel 1 in the response by stream_id and verify icon
    $jsonResponse = $response->json();
    $channel1Data = collect($jsonResponse)->firstWhere('stream_id', $enabledChannel1->id);
    $this->assertNotNull($channel1Data, 'Channel 1 should be in response');
    $this->assertStringContainsString('icon1.png', $channel1Data['stream_icon']);
    // direct_source field is present in the response structure
    $this->assertArrayHasKey('direct_source', $channel1Data);
    // Note: direct_source is currently not implemented and returns empty string
    $this->assertIsString($channel1Data['direct_source']);
});

it('includes stream stats when probed for get live streams', function () {
    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'profile' => 'High',
                'level' => 41,
                'width' => 1920,
                'height' => 1080,
                'bit_rate' => '5000000',
                'avg_frame_rate' => '25/1',
                'bits_per_raw_sample' => '8',
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'channels' => 2,
                'sample_rate' => '48000',
                'bit_rate' => '128000',
            ]],
        ],
        'stream_stats_probed_at' => now(),
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_live_streams'));

    $response->assertStatus(200)->assertJsonCount(1);

    $jsonResponse = $response->json();
    $channelData = collect($jsonResponse)->firstWhere('stream_id', $channel->id);

    $this->assertNotNull($channelData);
    $this->assertArrayHasKey('stream_stats', $channelData);
    $this->assertEquals('1920x1080', $channelData['stream_stats']['resolution']);
    $this->assertEquals('h264', $channelData['stream_stats']['video_codec']);
    $this->assertEquals('High', $channelData['stream_stats']['video_profile']);
    $this->assertEquals(41, $channelData['stream_stats']['video_level']);
    $this->assertEquals('aac', $channelData['stream_stats']['audio_codec']);
    $this->assertEquals('stereo', $channelData['stream_stats']['audio_channels']);
});

it('omits stream stats when not probed for get live streams', function () {
    $group = Group::factory()->for($this->user)->create();
    Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'stream_stats' => null,
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_live_streams'));

    $response->assertStatus(200)->assertJsonCount(1);

    $jsonResponse = $response->json();
    $this->assertArrayNotHasKey('stream_stats', $jsonResponse[0]);
});

it('returns an empty list for get live streams when there are no channels', function () {
    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_live_streams'));
    $response->assertStatus(200)->assertExactJson([]);
});

// Tests for get_vod_streams - returns VOD channels (movies), not Series
it('returns vod streams successfully', function () {
    $group = Group::factory()->for($this->user)->create();
    Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Enabled VOD 1',
        'logo' => 'https://example.com/cover1.jpg',
    ]);
    Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Enabled VOD 2',
    ]);
    Channel::factory()->for($this->playlist)->create([
        'enabled' => false,
        'is_vod' => true,
        'title' => 'Disabled VOD',
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_streams'));

    $response->assertStatus(200)
        ->assertJsonCount(2)
        ->assertJsonFragment(['name' => 'Enabled VOD 1'])
        ->assertJsonFragment(['name' => 'Enabled VOD 2'])
        ->assertJsonMissing(['name' => 'Disabled VOD']);

    $response->assertJsonStructure([
        '*' => [
            'num',
            'name',
            'stream_type',
            'stream_id',
            'stream_icon',
            'category_id',
        ],
    ]);
});

it('returns an empty list for get vod streams when there is no vod', function () {
    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_streams'));
    $response->assertStatus(200)->assertExactJson([]);
});

it('falls back to VOD metadata when the channel year is missing', function () {
    $group = Group::factory()->for($this->user)->create();
    Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Metadata Year Movie',
        'year' => null,
        'info' => ['release_date' => '2024-05-17'],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_streams'));

    $response->assertOk()
        ->assertJsonPath('0.year', 2024);
});

// Tests for get_vod_info - returns VOD channel (movie) info, not Series
it('returns vod info successfully', function () {
    $group = Group::factory()->for($this->user)->create();
    $vodChannel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'name' => 'Test Movie',
        'title' => 'Test Movie',
        'logo' => 'https://example.com/movie_cover.jpg',
        'year' => '2024',
        'rating' => 8.5,
        'last_metadata_fetch' => now(), // Skip metadata fetch in test
        'info' => [
            'name' => 'Test Movie',
            'cover_big' => 'https://example.com/movie_cover.jpg',
            'movie_image' => 'https://example.com/movie_cover.jpg',
            'release_date' => '2024',
            'plot' => 'A test movie plot.',
            'rating' => 8.5,
        ],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $vodChannel->id]));

    $response->assertStatus(200)
        ->assertJsonStructure([
            'info' => ['name', 'cover_big', 'movie_image', 'release_date', 'plot', 'rating'],
            'movie_data' => ['stream_id', 'name', 'title', 'year', 'category_id', 'container_extension'],
        ])
        ->assertJsonPath('movie_data.stream_id', $vodChannel->id)
        ->assertJsonPath('movie_data.name', 'Test Movie');
});

it('falls back to VOD metadata when the channel year is missing for get vod info', function () {
    $group = Group::factory()->for($this->user)->create();
    $vodChannel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Metadata Year Movie',
        'year' => null,
        'rating' => 8.5,
        'last_metadata_fetch' => now(), // Skip metadata fetch in test
        'info' => ['release_date' => '2024-05-17'],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $vodChannel->id]));

    $response->assertOk()
        ->assertJsonPath('movie_data.year', 2024);
});

it('prefers TMDB rating from info over provider rating on get vod info when both present', function () {
    // Regression guard for the precedence flip in XtreamApiController::get_vod_info:
    // info['rating'] is exclusively TMDB-origin, so when both are set it must win
    // over the raw provider column. Issue #1435.
    $group = Group::factory()->for($this->user)->create();
    $vodChannel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'TMDB Wins Movie',
        'rating' => 10.0, // Implausible provider rating
        'last_metadata_fetch' => now(), // Skip metadata fetch in test
        'info' => [
            'rating' => 6.5, // Real TMDB rating
        ],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $vodChannel->id]));

    $response->assertOk()
        ->assertJsonPath('info.rating', 6.5)
        ->assertJsonPath('rating', 6.5);
});

it('falls back to provider rating on get vod info when info rating is absent', function () {
    // Regression guard for the fallback path - when only the provider rating is set,
    // the response must surface that value (not '' or null). `channels.rating` is a
    // string DB column, so the response carries it as a string - compare loosely.
    $group = Group::factory()->for($this->user)->create();
    $vodChannel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Provider Only Movie',
        'rating' => 7.0,
        'last_metadata_fetch' => now(),
        'info' => [],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $vodChannel->id]));

    $response->assertOk()
        ->assertJsonPath('info.rating', fn ($v) => (float) $v === 7.0)
        ->assertJsonPath('rating', fn ($v) => (float) $v === 7.0);
});

it('prefers TMDB rating from info over provider rating on get vod streams when both present', function () {
    // Same precedence flip in the streaming list endpoint.
    $group = Group::factory()->for($this->user)->create();
    Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'TMDB Wins Streams Movie',
        'rating' => 10.0, // Provider
        'info' => ['rating' => 6.5], // TMDB
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_streams'));

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'TMDB Wins Streams Movie', 'rating' => 6.5]);
});

it('falls back to provider rating on get vod streams when info rating is absent', function () {
    $group = Group::factory()->for($this->user)->create();
    Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Provider Only Streams Movie',
        'rating' => 7.0,
        'info' => [],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_streams'));

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'Provider Only Streams Movie', 'rating' => '7']);
});

// Vote count threshold tests — TMDB ratings backed by too few votes are suppressed (issue #1436)

it('suppresses VOD rating on get vod info when tmdb vote_count is below threshold', function () {
    $group = Group::factory()->for($this->user)->create();
    $vodChannel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Low Votes Movie',
        'rating' => 8.0,
        'last_metadata_fetch' => now(),
        'info' => [
            'rating' => 6.5,
            'vote_count' => 3,
        ],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $vodChannel->id]));

    $response->assertOk()
        ->assertJsonPath('info.rating', '')
        ->assertJsonPath('rating', '');
});

it('surfaces VOD rating on get vod info when tmdb vote_count is at or above threshold', function () {
    $group = Group::factory()->for($this->user)->create();
    $vodChannel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Enough Votes Movie',
        'rating' => 8.0,
        'last_metadata_fetch' => now(),
        'info' => [
            'rating' => 6.5,
            'vote_count' => 25,
        ],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $vodChannel->id]));

    $response->assertOk()
        ->assertJsonPath('info.rating', 6.5)
        ->assertJsonPath('rating', 6.5);
});

it('surfaces VOD rating on get vod info when vote_count is absent (unknown is not low)', function () {
    $group = Group::factory()->for($this->user)->create();
    $vodChannel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Unknown Vote Count Movie',
        'rating' => 8.0,
        'last_metadata_fetch' => now(),
        'info' => [
            'rating' => 6.5,
            // no vote_count key — simulates a pre-#1436 fetch
        ],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $vodChannel->id]));

    $response->assertOk()
        ->assertJsonPath('info.rating', 6.5)
        ->assertJsonPath('rating', 6.5);
});

it('suppresses VOD rating on get vod streams when tmdb vote_count is below threshold', function () {
    $group = Group::factory()->for($this->user)->create();
    Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Low Votes Streams Movie',
        'rating' => 8.0,
        'info' => [
            'rating' => 6.5,
            'vote_count' => 3,
        ],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_streams'));

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'Low Votes Streams Movie', 'rating' => '']);
});

it('surfaces VOD rating on get vod streams when tmdb vote_count is at or above threshold', function () {
    $group = Group::factory()->for($this->user)->create();
    Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Enough Votes Streams Movie',
        'rating' => 8.0,
        'info' => [
            'rating' => 6.5,
            'vote_count' => 25,
        ],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_streams'));

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'Enough Votes Streams Movie', 'rating' => 6.5]);
});

it('surfaces VOD rating on get vod streams when vote_count is absent', function () {
    $group = Group::factory()->for($this->user)->create();
    Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Unknown Vote Count Streams Movie',
        'rating' => 8.0,
        'info' => [
            'rating' => 6.5,
            // no vote_count key
        ],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_streams'));

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'Unknown Vote Count Streams Movie', 'rating' => 6.5]);
});

it('suppresses series rating on get series info when tmdb vote_count is below threshold', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Low Votes Series',
        'rating' => 8.0,
        'metadata' => ['tmdb' => '', 'vote_count' => 3],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk()
        ->assertJsonPath('info.rating', '');
});

it('suppresses series rating on get series when tmdb vote_count is below threshold', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Low Votes Series List',
        'rating' => 8.0,
        'metadata' => ['tmdb' => '', 'vote_count' => 3],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series'));

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'Low Votes Series List', 'rating' => '']);
});

it('surfaces series rating on get series info when tmdb vote_count is at or above threshold', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Solid Votes Series',
        'rating' => 8.0,
        'metadata' => ['tmdb' => '', 'vote_count' => 25],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk()
        ->assertJsonPath('info.rating', fn ($v) => (float) $v === 8.0);
});

it('surfaces series rating on get series info when vote_count is absent (unknown is not low)', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Unknown Vote Count Series',
        'rating' => 7.5,
        'metadata' => ['tmdb' => ''],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk()
        ->assertJsonPath('info.rating', fn ($v) => (float) $v === 7.5);
});

it('surfaces series rating on get series when tmdb vote_count is at or above threshold', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Solid Votes Series List',
        'rating' => 8.0,
        'metadata' => ['tmdb' => '', 'vote_count' => 25],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series'));

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'Solid Votes Series List', 'rating' => '8']);
});

it('surfaces series rating on get series when vote_count is absent (unknown is not low)', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Unknown Vote Count Series List',
        'rating' => 7.5,
        'metadata' => ['tmdb' => ''],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series'));

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'Unknown Vote Count Series List', 'rating' => '7.5']);
});

it('suppresses series rating_5based on get series info when tmdb vote_count is below threshold', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Low Votes Series 5Based',
        'rating' => 8.0,
        'metadata' => ['tmdb' => '', 'vote_count' => 3],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk()
        ->assertJsonPath('info.rating', '')
        ->assertJsonPath('info.rating_5based', 0);
});

it('surfaces series rating_5based on get series info when tmdb vote_count is at or above threshold', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Solid Votes Series 5Based',
        'rating' => 8.0,
        'metadata' => ['tmdb' => '', 'vote_count' => 25],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk()
        ->assertJsonPath('info.rating_5based', fn ($v) => (float) $v === 4.0);
});

it('suppresses series rating_5based on get series list when tmdb vote_count is below threshold', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Low Votes Series List 5Based',
        'rating' => 8.0,
        'metadata' => ['tmdb' => '', 'vote_count' => 3],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series'));

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'Low Votes Series List 5Based', 'rating' => '', 'rating_5based' => 0]);
});

it('suppresses episode rating on get series info when tmdb vote_count is below threshold', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Series With Low Vote Episode',
        'metadata' => ['tmdb' => ''],
    ]);

    $season = Season::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
        'series_id' => $series->id,
        'season_number' => 1,
        'episode_count' => 1,
    ]);

    Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'season' => 1,
        'episode_num' => 1,
        'enabled' => true,
        'info' => [
            'rating' => 10.0,
            'vote_count' => 1,
        ],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk();
    $episodes = $response->json('episodes.1');
    expect($episodes)->toHaveCount(1);
    expect($episodes[0]['info']['rating'])->toBe('');
});

it('surfaces episode rating on get series info when tmdb vote_count is at or above threshold', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Series With Solid Vote Episode',
        'metadata' => ['tmdb' => ''],
    ]);

    $season = Season::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
        'series_id' => $series->id,
        'season_number' => 1,
        'episode_count' => 1,
    ]);

    Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'season' => 1,
        'episode_num' => 1,
        'enabled' => true,
        'info' => [
            'rating' => 9.0,
            'vote_count' => 25,
        ],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk();
    $episodes = $response->json('episodes.1');
    expect($episodes)->toHaveCount(1);
    expect((float) $episodes[0]['info']['rating'])->toBe(9.0);
});

it('returns not found for get vod info when vod is missing', function () {
    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => 99999]));
    $response->assertStatus(404)
        ->assertJson(['error' => 'VOD not found']);
});

it('returns not found for get vod info with an invalid vod id format', function () {
    // PostgreSQL throws an error on non-numeric ID, so we test with a very large numeric ID instead
    // to verify 404 response for non-existent VOD
    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => 9999999]));
    $response->assertStatus(404)
        ->assertJson(['error' => 'VOD not found']);
});

it('returns not found for get vod info on a disabled vod', function () {
    $group = Group::factory()->for($this->user)->create();
    $disabledVod = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => false,
        'is_vod' => true,
        'title' => 'Disabled VOD',
    ]);
    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $disabledVod->id]));
    $response->assertStatus(404)
        ->assertJson(['error' => 'VOD not found']);
});

it('returns an error for get vod info when the vod id parameter is missing', function () {
    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info')); // No vod_id param
    $response->assertStatus(400)
        ->assertJson(['error' => 'vod_id parameter is required for get_vod_info action']);
});

/**
 * Test that the merge and unmerge channel jobs work correctly.
 */
it('merges and unmerges channel jobs correctly', function () {
    // Create channels with the same stream_id (explicit sort order so channel1 is master)
    $channel1 = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'stream_id' => '100',
        'user_id' => $this->user->id,
        'sort' => 1.0,
        'enabled' => true,
        'can_merge' => true,
    ]);
    $channel2 = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'stream_id' => '100',
        'user_id' => $this->user->id,
        'sort' => 2.0,
        'enabled' => true,
        'can_merge' => true,
    ]);
    $channel3 = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'stream_id' => '100',
        'user_id' => $this->user->id,
        'sort' => 3.0,
        'enabled' => true,
        'can_merge' => true,
    ]);

    // Run the merge job with required arguments: user, playlists (as collection with playlist_failover_id), playlistId
    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);
    (new MergeChannels($this->user, $playlists, $this->playlist->id))->handle();

    // Assert that failover records were created
    $this->assertDatabaseCount('channel_failovers', 2);
    $this->assertDatabaseHas('channel_failovers', ['channel_id' => $channel1->id, 'channel_failover_id' => $channel2->id]);
    $this->assertDatabaseHas('channel_failovers', ['channel_id' => $channel1->id, 'channel_failover_id' => $channel3->id]);

    // Run the unmerge job - UnmergeChannels expects (user, playlistId)
    (new UnmergeChannels($this->user, $this->playlist->id))->handle();

    // Assert that failover records were deleted
    $this->assertDatabaseCount('channel_failovers', 0);
});

it('returns an error when the get short epg action is missing a stream id', function () {
    $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'username' => $this->username,
        'password' => $this->password,
        'action' => 'get_short_epg',
    ]));

    $response->assertStatus(400)
        ->assertJson([
            'error' => 'stream_id parameter is required for get_short_epg action',
        ]);
});

it('returns an error when the get short epg action targets a non existent channel', function () {
    $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'username' => $this->username,
        'password' => $this->password,
        'action' => 'get_short_epg',
        'stream_id' => 99999,
    ]));

    $response->assertStatus(404)
        ->assertJson([
            'error' => 'Channel not found',
        ]);
});

it('returns an empty list when the get short epg action targets a channel without epg', function () {
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'enabled' => true,
        'is_vod' => false,
        'user_id' => $this->user->id,
    ]);

    $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'username' => $this->username,
        'password' => $this->password,
        'action' => 'get_short_epg',
        'stream_id' => $channel->id,
    ]));

    $response->assertStatus(200)
        ->assertJson([
            'epg_listings' => [],
        ]);
});

it('returns an error when the get simple data table action is missing a stream id', function () {
    $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'username' => $this->username,
        'password' => $this->password,
        'action' => 'get_simple_data_table',
    ]));

    $response->assertStatus(400)
        ->assertJson([
            'error' => 'stream_id parameter is required for get_simple_data_table action',
        ]);
});

it('returns an error when the get simple data table action targets a non existent channel', function () {
    $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'username' => $this->username,
        'password' => $this->password,
        'action' => 'get_simple_data_table',
        'stream_id' => 99999,
    ]));

    $response->assertStatus(404)
        ->assertJson([
            'error' => 'Channel not found',
        ]);
});

it('returns an empty list when the get simple data table action targets a channel without epg', function () {
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'enabled' => true,
        'is_vod' => false,
        'user_id' => $this->user->id,
    ]);

    $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'username' => $this->username,
        'password' => $this->password,
        'action' => 'get_simple_data_table',
        'stream_id' => $channel->id,
    ]));

    $response->assertStatus(200)
        ->assertJson([
            'epg_listings' => [],
        ]);
});

it('respects the limit parameter for the get short epg action', function () {
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'enabled' => true,
        'is_vod' => false,
        'user_id' => $this->user->id,
    ]);

    // Test with limit parameter
    $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'username' => $this->username,
        'password' => $this->password,
        'action' => 'get_short_epg',
        'stream_id' => $channel->id,
        'limit' => 2,
    ]));

    $response->assertStatus(200)
        ->assertJsonStructure([
            'epg_listings',
        ]);

    // Test default limit (should be 4)
    $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'username' => $this->username,
        'password' => $this->password,
        'action' => 'get_short_epg',
        'stream_id' => $channel->id,
    ]));

    $response->assertStatus(200)
        ->assertJsonStructure([
            'epg_listings',
        ]);
});

// Tests for timeshift functionality
it('allows timeshift stream access with valid credentials', function () {
    // Create a channel for this playlist
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://test-stream.com/live/stream123.ts',
    ]);

    // Test timeshift URL structure: /timeshift/{username}/{password}/{duration}/{date}/{streamId}.{format}
    $response = $this->get(route('xtream.stream.timeshift.root', [
        'username' => $this->username,
        'password' => $this->password,
        'duration' => 60, // 60 minutes
        'date' => '2024-12-01:15-30-00', // YYYY-MM-DD:HH-MM-SS format
        'streamId' => $channel->id,
        'format' => 'ts',
    ]));

    // Should redirect to stream URL (since proxy is likely disabled in test)
    $response->assertStatus(302);
});

it('denies timeshift stream access with invalid credentials', function () {
    // Create a channel for this playlist
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://test-stream.com/live/stream123.ts',
    ]);

    // Test with invalid credentials
    $response = $this->get(route('xtream.stream.timeshift.root', [
        'username' => 'invalid_user',
        'password' => 'invalid_pass',
        'duration' => 60,
        'date' => '2024-12-01:15-30-00',
        'streamId' => $channel->id,
        'format' => 'ts',
    ]));

    $response->assertStatus(403)
        ->assertJson(['error' => 'Unauthorized or stream not found']);
});

it('denies timeshift stream access for a disabled channel', function () {
    // Create a disabled channel for this playlist
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => false,
        'url' => 'https://test-stream.com/live/stream123.ts',
    ]);

    $response = $this->get(route('xtream.stream.timeshift.root', [
        'username' => $this->username,
        'password' => $this->password,
        'duration' => 60,
        'date' => '2024-12-01:15-30-00',
        'streamId' => $channel->id,
        'format' => 'ts',
    ]));

    $response->assertStatus(403)
        ->assertJson(['error' => 'Unauthorized or stream not found']);
});

it('denies timeshift stream access for a nonexistent channel', function () {
    $response = $this->get(route('xtream.stream.timeshift.root', [
        'username' => $this->username,
        'password' => $this->password,
        'duration' => 60,
        'date' => '2024-12-01:15-30-00',
        'streamId' => 99999, // Non-existent channel ID
        'format' => 'ts',
    ]));

    $response->assertStatus(403)
        ->assertJson(['error' => 'Unauthorized or stream not found']);
});

it('handles null backdrop paths for get series when logo proxy is enabled', function () {
    $this->playlist->update(['enable_logo_proxy' => true]);

    $category = Category::factory()->for($this->user)->create();

    Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
        'enabled' => true,
        'backdrop_path' => json_encode([null, 'https://example.com/img.jpg', null]),
        'cover' => 'https://example.com/cover.jpg',
        'metadata' => json_encode(['tmdb' => '', 'last_modified' => null]),
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series'));

    $response->assertOk();
    $response->assertJsonCount(1);
});

it('does not double-proxy an already app-hosted series cover when logo proxy is enabled', function () {
    $this->playlist->update(['enable_logo_proxy' => true]);

    $category = Category::factory()->for($this->user)->create();
    $localCover = url('/media-server-image-proxy/abc123/cover.jpg');
    $localBackdrop = url('/media-server-image-proxy/abc123/backdrop.jpg');

    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
        'enabled' => true,
        'cover' => $localCover,
        'backdrop_path' => json_encode([$localBackdrop]),
        'metadata' => json_encode(['tmdb' => '', 'last_modified' => null]),
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk()
        ->assertJsonPath('info.cover', $localCover)
        ->assertJsonPath('info.backdrop_path.0', $localBackdrop);
});

it('does not double-proxy an already app-hosted vod cover when logo proxy is enabled', function () {
    $this->playlist->update(['enable_logo_proxy' => true]);

    $group = Group::factory()->for($this->user)->create();
    $localCover = url('/media-server-image-proxy/abc123/cover.jpg');
    $vodChannel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'name' => 'Test Movie',
        'title' => 'Test Movie',
        'logo' => $localCover,
        'last_metadata_fetch' => now(),
        'info' => [
            'name' => 'Test Movie',
            'cover_big' => $localCover,
            'movie_image' => $localCover,
            'rating' => 8.5,
        ],
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $vodChannel->id]));

    $response->assertOk()
        ->assertJsonPath('info.cover_big', $localCover)
        ->assertJsonPath('info.movie_image', $localCover);
});

it('orders series categories by sort order for a regular playlist', function () {
    $third = Category::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Third',
        'sort_order' => 30,
    ]);
    $first = Category::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'First',
        'sort_order' => 10,
    ]);
    $second = Category::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Second',
        'sort_order' => 20,
    ]);

    foreach ([$first, $second, $third] as $category) {
        Series::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'category_id' => $category->id,
            'enabled' => true,
        ]);
    }

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series_categories'));

    $response->assertOk();
    $this->assertSame(
        ['First', 'Second', 'Third'],
        array_column($response->json(), 'category_name'),
    );
});

it('enables tv archive for live streams when shift is set', function () {
    $group = Group::factory()->for($this->user)->create();

    // Channel with shift > 0 but no catchup value (custom channel scenario).
    // shift is stored in hours; tv_archive_duration is reported in days (#1389).
    $channelWithShift = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'title_custom' => 'Channel With Shift',
        'shift' => 12,
        'catchup' => null,
    ]);

    // Channel with catchup and a known duration (provider-imported scenario).
    $channelWithCatchup = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'title_custom' => 'Channel With Catchup',
        'shift' => 48,
        'catchup' => 'default',
    ]);

    // Channel with catchup enabled but no known duration - retention is
    // unknown, not zero, so tv_archive_duration falls back to a sensible
    // default rather than asserting zero retention (#1389). It stays a
    // plain int (never null) for compatibility with third-party Xtream
    // clients like TiviMate that deserialize this as a non-nullable int.
    $channelWithUnknownDuration = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'title_custom' => 'Channel With Unknown Duration',
        'shift' => 0,
        'catchup' => 'default',
    ]);

    // Channel with no shift and no catchup
    $channelWithoutArchive = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'title_custom' => 'Channel Without Archive',
        'shift' => 0,
        'catchup' => null,
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_live_streams'));
    $response->assertStatus(200);

    $jsonResponse = $response->json();

    $shiftData = collect($jsonResponse)->firstWhere('stream_id', $channelWithShift->id);
    $this->assertEquals(1, $shiftData['tv_archive'], 'tv_archive should be 1 when shift > 0');
    $this->assertEquals(1, $shiftData['tv_archive_duration'], '12 hours rounds up to 1 day');

    $catchupData = collect($jsonResponse)->firstWhere('stream_id', $channelWithCatchup->id);
    $this->assertEquals(1, $catchupData['tv_archive'], 'tv_archive should be 1 when catchup is set');
    $this->assertEquals(2, $catchupData['tv_archive_duration'], '48 hours converts to 2 days');

    $unknownDurationData = collect($jsonResponse)->firstWhere('stream_id', $channelWithUnknownDuration->id);
    $this->assertEquals(1, $unknownDurationData['tv_archive'], 'tv_archive should be 1 when catchup is set even without a known duration');
    $this->assertEquals(config('dev.default_epg_catchup_days'), $unknownDurationData['tv_archive_duration'], 'unknown retention falls back to the configured default, not 0');

    $noArchiveData = collect($jsonResponse)->firstWhere('stream_id', $channelWithoutArchive->id);
    $this->assertEquals(0, $noArchiveData['tv_archive'], 'tv_archive should be 0 when no shift and no catchup');
    $this->assertEquals(0, $noArchiveData['tv_archive_duration']);
});

it('respects dev.default_epg_catchup_days=0 to advertise no retention instead of the default fallback', function () {
    config(['dev.default_epg_catchup_days' => 0]);

    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'title_custom' => 'Channel With Unknown Duration',
        'shift' => 0,
        'catchup' => 'default',
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_live_streams'));
    $response->assertStatus(200);

    $data = collect($response->json())->firstWhere('stream_id', $channel->id);
    $this->assertEquals(1, $data['tv_archive']);
    $this->assertEquals(0, $data['tv_archive_duration']);
});

it('returns valid json with episode count for dvr series info', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();

    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
        'enabled' => true,
        'import_batch_no' => 'dvr',
        'source_series_id' => null,
        'name' => 'DVR Test Show',
        'metadata' => null,
        'last_metadata_fetch' => null,
        'last_modified' => null,
    ]);

    $season = Season::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
        'series_id' => $series->id,
        'season_number' => 1,
        'episode_count' => 2,
        'import_batch_no' => 'dvr',
        'source_season_id' => null,
    ]);

    Episode::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'season' => 1,
        'import_batch_no' => 'dvr',
        'source_episode_id' => null,
        'enabled' => true,
    ]);

    $response = $this->getJson(getXtreamApiUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk();
    $response->assertJsonStructure(['info', 'seasons', 'episodes']);

    $seasons = $response->json('seasons');
    $this->assertCount(1, $seasons);
    $this->assertSame(2, $seasons[0]['episode_count']);
});
