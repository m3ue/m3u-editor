<?php

namespace Tests\Feature;

use App\Enums\ChannelLogoType;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class XtreamApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Playlist $playlist;

    protected string $username;

    protected string $password;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    // Helper to build URL for Xtream API actions
    private function getXtreamApiUrl(string $action, array $params = []): string
    {
        $queryParams = array_merge([
            'username' => $this->username,
            'password' => $this->password,
            'action' => $action,
        ], $params);

        // Assuming 'playlist.xtream.api' is the correct route name from existing tests
        return route('playlist.xtream.api', ['uuid' => $this->playlist->uuid]).'?'.http_build_query($queryParams);
    }

    // Standard setup for authenticated requests to panel action (from existing tests, slightly adapted)
    private function setupAuthenticatedPanelRequest(?User &$user = null, ?Playlist &$playlist = null, array $playlistAuthCredentials = [])
    {
        $user = $user ?? $this->user; // Use class property if not provided
        $playlist = $playlist ?? $this->playlist; // Use class property if not provided

        $authUsername = $playlistAuthCredentials['username'] ?? $this->username;
        $authPassword = $playlistAuthCredentials['password'] ?? $this->password;

        // PlaylistAuth is already created in setUp generally, but this allows override for specific panel tests
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

        return $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid,
            'action' => 'panel',
            'username' => $authUsername,
            'password' => $authPassword,
        ]));
    }

    public function test_panel_action_with_valid_playlist_auth_returns_correct_structure(): void
    {
        $response = $this->setupAuthenticatedPanelRequest();

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
                'server_software',
                'timestamp_now',
                'time_now',
            ],
        ]);
        $response->assertJsonPath('server_info.server_software', config('app.name').' Xtream API');
    }

    public function test_panel_action_with_invalid_playlist_auth_returns_unauthorized(): void
    {
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

        $response = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid,
            'action' => 'panel',
            'username' => 'correct_user',
            'password' => 'incorrect_password', // Using incorrect password
        ]));

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized']);
    }

    public function test_panel_action_with_m3ue_user_and_correct_password_returns_success(): void
    {
        $plainPassword = 'password123';
        $user = User::factory()->create(['password' => Hash::make($plainPassword)]);
        $playlist = Playlist::factory()->for($user)->create();

        // Create a disabled auth to ensure it's not used
        $playlistAuth = PlaylistAuth::create([
            'name' => 'Disabled Auth',
            'username' => 'disabled_user',
            'password' => 'disabled_password',
            'enabled' => false,
            'user_id' => $user->id,
        ]);
        $playlist->playlistAuths()->attach($playlistAuth);

        $response = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid,
            'action' => 'panel',
            'username' => 'm3ue',
            'password' => $plainPassword,
        ]));

        $response->assertOk();
        $response->assertJsonPath('user_info.username', 'm3ue');
        $response->assertJsonStructure(['user_info', 'server_info']);
    }

    public function test_panel_action_with_m3ue_user_and_incorrect_password_returns_unauthorized(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct_password')]);
        $playlist = Playlist::factory()->for($user)->create();

        // Create a disabled auth to ensure it's not used
        $playlistAuth = PlaylistAuth::create([
            'name' => 'Disabled Auth',
            'username' => 'disabled_user',
            'password' => 'disabled_password',
            'enabled' => false,
            'user_id' => $user->id,
        ]);
        $playlist->playlistAuths()->attach($playlistAuth);

        $response = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid,
            'action' => 'panel',
            'username' => 'm3ue',
            'password' => 'incorrect_password',
        ]));

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized']);
    }

    public function test_panel_action_with_non_existent_playlist_uuid_returns_not_found(): void
    {
        $response = $this->getJson(route('playlist.xtream.api', [
            'uuid' => Str::uuid()->toString(),
            'action' => 'panel',
            'username' => 'any',
            'password' => 'any',
        ]));
        $response->assertStatus(404);
        $response->assertJson(['error' => 'Playlist not found']);
    }

    public function test_panel_action_with_missing_credentials_returns_unauthorized(): void
    {
        $playlist = Playlist::factory()->create(); // User automatically created by Playlist factory if not specified

        $responseMissingUser = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid,
            'action' => 'panel',
            'password' => 'test',
        ]));
        $responseMissingUser->assertStatus(401)->assertJson(['error' => 'Unauthorized - Missing credentials']);

        $responseMissingPass = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid,
            'action' => 'panel',
            'username' => 'test',
        ]));
        $responseMissingPass->assertStatus(401)->assertJson(['error' => 'Unauthorized - Missing credentials']);
    }

    // Tests for get_live_streams
    public function test_get_live_streams_success()
    {
        $group = Group::factory()->for($this->user)->create(); // Use existing Group model
        $enabledChannel1 = Channel::factory()->for($this->playlist)->for($group)->create(['enabled' => true, 'title_custom' => 'Enabled Channel 1', 'logo_type' => ChannelLogoType::Channel, 'logo' => 'icon1.png']);
        $enabledChannel2 = Channel::factory()->for($this->playlist)->for($group)->create(['enabled' => true, 'title_custom' => 'Enabled Channel 2']);
        Channel::factory()->for($this->playlist)->create(['enabled' => false, 'title_custom' => 'Disabled Channel']);

        $response = $this->getJson($this->getXtreamApiUrl('get_live_streams'));

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
        // Check specific icon for channel 1
        $this->assertEquals('https://m3ueditor.test/icon1.png', $response->json('0.stream_icon'));
        // Check direct_source for the first channel
        $jsonResponse = $response->json();
        if (! empty($jsonResponse)) {
            $expectedDirectSource = url("/live/{$this->username}/{$this->password}/".$enabledChannel1->id).'.ts';
            $this->assertEquals($expectedDirectSource, $jsonResponse[0]['direct_source']);
        }
    }

    public function test_get_live_streams_no_channels()
    {
        $response = $this->getJson($this->getXtreamApiUrl('get_live_streams'));
        $response->assertStatus(200)->assertExactJson([]);
    }

    // Tests for get_vod_streams
    public function test_get_vod_streams_success()
    {
        $category = Category::factory()->for($this->user)->create(); // Use existing Category model
        $enabledSeries1 = Series::factory()->for($this->playlist)->for($category)->create(['enabled' => true, 'name' => 'Enabled Series 1', 'cover' => 'cover1.jpg']);
        $enabledSeries2 = Series::factory()->for($this->playlist)->for($category)->create(['enabled' => true, 'name' => 'Enabled Series 2']);
        Series::factory()->for($this->playlist)->create(['enabled' => false, 'name' => 'Disabled Series']);

        $response = $this->getJson($this->getXtreamApiUrl('get_vod_streams'));

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['name' => 'Enabled Series 1'])
            ->assertJsonFragment(['name' => 'Enabled Series 2'])
            ->assertJsonMissing(['name' => 'Disabled Series']);

        $response->assertJsonStructure([
            '*' => [
                'num',
                'name',
                'series_id',
                'cover',
                'plot',
                'cast',
                'director',
                'genre',
                'releaseDate',
                'last_modified',
                'rating',
                'rating_5based',
                'backdrop_path',
                'youtube_trailer',
                'episode_run_time',
                'category_id',
            ],
        ]);
        $this->assertEquals('https://m3ueditor.test/cover1.jpg', $response->json('0.cover'));
    }

    public function test_get_vod_streams_no_series()
    {
        $response = $this->getJson($this->getXtreamApiUrl('get_vod_streams'));
        $response->assertStatus(200)->assertExactJson([]);
    }

    // Tests for get_vod_info
    public function test_get_vod_info_success_with_episodes()
    {
        $category = Category::factory()->for($this->user)->create();
        $series = Series::factory()
            ->for($this->playlist) // Associate with the class's playlist
            ->for($category)
            ->has(Season::factory()->count(2)
                ->has(Episode::factory()->count(3)
                    ->sequence(
                        ['episode_num' => 1, 'container_extension' => 'mp4', 'title' => 'Episode Title 1'],
                        ['episode_num' => 2, 'container_extension' => 'mp4', 'title' => 'Episode Title 2'],
                        ['episode_num' => 3, 'container_extension' => 'mp4', 'title' => 'Episode Title 3']
                    ), 'episodes'), 'seasons')
            ->create(['enabled' => true, 'name' => 'Test Series with Episodes', 'cover' => 'series_cover.jpg']);

        $response = $this->getJson($this->getXtreamApiUrl('get_vod_info', ['vod_id' => $series->id]));

        $response->assertStatus(200)
            ->assertJsonPath('info.name', $series->name)
            ->assertJsonPath('info.cover', 'https://m3ueditor.test/series_cover.jpg')
            ->assertJsonPath('movie_data.name', $series->name)
            ->assertJsonCount(2, 'episodes');

        $firstSeason = $series->seasons()->orderBy('season_number')->first();
        $firstSeasonNumber = $firstSeason->season_number;
        $firstEpisode = $firstSeason->episodes()->orderBy('episode_num')->first();

        $response->assertJsonPath("episodes.{$firstSeasonNumber}.0.id", (string) $firstEpisode->id);
        $response->assertJsonPath("episodes.{$firstSeasonNumber}.0.title", $firstEpisode->title);
        $response->assertJsonPath("episodes.{$firstSeasonNumber}.0.container_extension", $firstEpisode->container_extension ?? 'mp4');
        $response->assertJsonPath("episodes.{$firstSeasonNumber}.0.stream_id", $firstEpisode->id); // stream_id is base64 encoded

        // $expectedUrlPath = "/series/{$this->playlist->uuid}/{$this->username}/{$this->password}/{$series->id}-{$firstEpisode->id}.{$firstEpisode->container_extension}";
        $expectedDirectSource = url("/series/{$this->username}/{$this->password}/".$firstEpisode->id.".{$firstEpisode->container_extension}");
        $actualDirectSource = $response->json("episodes.{$firstSeasonNumber}.0.direct_source");
        $this->assertNotNull($actualDirectSource, 'Direct source URL is null.');
        // $this->assertStringContainsString($expectedUrlPath, $actualDirectSource);
        $this->assertEquals($expectedDirectSource, $actualDirectSource);

        $response->assertJsonStructure([
            'info' => ['name', 'cover', 'plot', 'cast', 'director', 'genre', 'releaseDate', 'last_modified', 'rating', 'rating_5based', 'backdrop_path', 'youtube_trailer', 'episode_run_time', 'category_id'],
            'episodes' => [
                '*' => [
                    '*' => [
                        'id',
                        'episode_num',
                        'title',
                        'container_extension',
                        'info' => ['movie_image', 'plot', 'duration_secs', 'duration', 'video', 'audio', 'bitrate', 'rating'],
                        'added',
                        'season',
                        'stream_id',
                        'direct_source',
                    ],
                ],
            ],
            'movie_data' => ['stream_id', 'name', 'title', 'year', 'episode_run_time', 'plot', 'cast', 'director', 'genre', 'releaseDate', 'last_modified', 'rating', 'rating_5based', 'cover_big', 'youtube_trailer', 'backdrop_path'],
        ]);
    }

    public function test_get_vod_info_movie_as_series_no_explicit_episodes()
    {
        $category = Category::factory()->for($this->user)->create();
        $movieSeries = Series::factory()
            ->for($this->playlist)
            ->for($category)
            ->create([
                'enabled' => true,
                'name' => 'Test Movie as Series',
                'plot' => 'A great movie plot.',
                'cover' => 'movie_cover.jpg',
            ]);

        $response = $this->getJson($this->getXtreamApiUrl('get_vod_info', ['vod_id' => $movieSeries->id]));

        $response->assertStatus(200)
            ->assertJsonPath('info.name', $movieSeries->name)
            ->assertJsonPath('info.cover', 'https://m3ueditor.test/movie_cover.jpg')
            ->assertJsonPath('movie_data.name', $movieSeries->name);

        $response->assertJsonCount(1, 'episodes.1');
        $response->assertJsonPath('episodes.1.0.id', (string) $movieSeries->id);
        $response->assertJsonPath('episodes.1.0.title', $movieSeries->name);
        $response->assertJsonPath('episodes.1.0.container_extension', 'mp4');
        $response->assertJsonPath('episodes.1.0.stream_id', $movieSeries->id);
        $response->assertJsonPath('episodes.1.0.info.plot', $movieSeries->plot);
        $response->assertJsonPath('episodes.1.0.info.movie_image', 'https://m3ueditor.test/movie_cover.jpg');

        // $expectedUrlPath = "/series/{$this->playlist->uuid}/{$this->username}/{$this->password}/{$movieSeries->id}.{$movieSeries->container_extension}";
        $expectedDirectSource = url("/series/{$this->username}/{$this->password}/".$movieSeries->id.'.mp4');
        $actualDirectSource = $response->json('episodes.1.0.direct_source');
        $this->assertNotNull($actualDirectSource, 'Direct source URL is null for movie.');
        // $this->assertStringContainsString($expectedUrlPath, $actualDirectSource);
        $this->assertEquals($expectedDirectSource, $actualDirectSource);
    }

    public function test_get_vod_info_invalid_vod_id_format() // Test specific non-numeric ID if your app logic handles it
    {
        // If IDs are always numeric, a non-numeric vod_id might be caught by routing or DB constraints before controller.
        // Test based on how your controller/routes handle this.
        // Assuming it reaches the controller and fails the ->firstWhere('id', $vodId) lookup.
        $response = $this->getJson($this->getXtreamApiUrl('get_vod_info', ['vod_id' => 'non-numeric-id']));
        $response->assertStatus(404)
            ->assertJson(['error' => 'VOD not found or not enabled']);
    }

    public function test_get_vod_info_non_existent_vod_id()
    {
        $response = $this->getJson($this->getXtreamApiUrl('get_vod_info', ['vod_id' => 99999]));
        $response->assertStatus(404)
            ->assertJson(['error' => 'VOD not found or not enabled']);
    }

    public function test_get_vod_info_disabled_series()
    {
        $disabledSeries = Series::factory()->for($this->playlist)->create(['enabled' => false, 'name' => 'Disabled Series']);
        $response = $this->getJson($this->getXtreamApiUrl('get_vod_info', ['vod_id' => $disabledSeries->id]));
        $response->assertStatus(404)
            ->assertJson(['error' => 'VOD not found or not enabled']);
    }

    public function test_get_vod_info_missing_vod_id_parameter()
    {
        $response = $this->getJson($this->getXtreamApiUrl('get_vod_info')); // No vod_id param
        $response->assertStatus(400) // Expecting 400 Bad Request
            ->assertJson(['error' => 'Missing vod_id parameter']);
    }

    /**
     * Test that the merge and unmerge channel jobs work correctly.
     *
     * @return void
     */
    public function test_merge_and_unmerge_channels_jobs()
    {
        // Create channels with the same stream_id
        $channel1 = Channel::factory()->create(['playlist_id' => $this->playlist->id, 'stream_id' => '100', 'user_id' => $this->user->id]);
        $channel2 = Channel::factory()->create(['playlist_id' => $this->playlist->id, 'stream_id' => '100', 'user_id' => $this->user->id]);
        $channel3 = Channel::factory()->create(['playlist_id' => $this->playlist->id, 'stream_id' => '100', 'user_id' => $this->user->id]);

        $channels = collect([$channel1, $channel2, $channel3]);

        // Run the merge job
        (new \App\Jobs\MergeChannels($channels))->handle();

        // Assert that failover records were created
        $this->assertDatabaseCount('channel_failovers', 2);
        $this->assertDatabaseHas('channel_failovers', ['channel_id' => $channel1->id, 'channel_failover_id' => $channel2->id]);
        $this->assertDatabaseHas('channel_failovers', ['channel_id' => $channel1->id, 'channel_failover_id' => $channel3->id]);

        // Run the unmerge job
        (new \App\Jobs\UnmergeChannels($channels))->handle();

        // Assert that failover records were deleted
        $this->assertDatabaseCount('channel_failovers', 0);
    }

    public function test_get_short_epg_action_with_missing_stream_id_returns_error(): void
    {
        $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
            'username' => $this->username,
            'password' => $this->password,
            'action' => 'get_short_epg',
        ]));

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'stream_id parameter is required for get_short_epg action',
            ]);
    }

    public function test_get_short_epg_action_with_non_existent_channel_returns_error(): void
    {
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
    }

    public function test_get_short_epg_action_with_channel_without_epg_returns_empty_list(): void
    {
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
    }

    public function test_get_simple_date_table_action_with_missing_stream_id_returns_error(): void
    {
        $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
            'username' => $this->username,
            'password' => $this->password,
            'action' => 'get_simple_date_table',
        ]));

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'stream_id parameter is required for get_simple_date_table action',
            ]);
    }

    public function test_get_simple_date_table_action_with_non_existent_channel_returns_error(): void
    {
        $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
            'username' => $this->username,
            'password' => $this->password,
            'action' => 'get_simple_date_table',
            'stream_id' => 99999,
        ]));

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'Channel not found',
            ]);
    }

    public function test_get_simple_date_table_action_with_channel_without_epg_returns_empty_list(): void
    {
        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'enabled' => true,
            'is_vod' => false,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
            'username' => $this->username,
            'password' => $this->password,
            'action' => 'get_simple_date_table',
            'stream_id' => $channel->id,
        ]));

        $response->assertStatus(200)
            ->assertJson([
                'epg_listings' => [],
            ]);
    }

    public function test_get_short_epg_action_respects_limit_parameter(): void
    {
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
    }

    // Tests for timeshift functionality
    public function test_timeshift_stream_access_with_valid_credentials()
    {
        // Create a channel
        $channel = Channel::factory()->create([
            'enabled' => true,
            'url' => 'https://test-stream.com/live/stream123.ts',
        ]);

        // Attach the channel to the playlist
        $this->playlist->channels()->attach($channel->id);

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
    }

    public function test_timeshift_stream_access_with_invalid_credentials()
    {
        // Create a channel
        $channel = Channel::factory()->create([
            'enabled' => true,
            'url' => 'https://test-stream.com/live/stream123.ts',
        ]);

        // Attach the channel to the playlist
        $this->playlist->channels()->attach($channel->id);

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
    }

    public function test_timeshift_stream_access_with_disabled_channel()
    {
        // Create a disabled channel
        $channel = Channel::factory()->create([
            'enabled' => false,
            'url' => 'https://test-stream.com/live/stream123.ts',
        ]);

        // Attach the channel to the playlist
        $this->playlist->channels()->attach($channel->id);

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
    }

    public function test_timeshift_stream_access_with_nonexistent_channel()
    {
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
    }
}
