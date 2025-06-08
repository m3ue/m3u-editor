<?php

namespace Tests\Feature;

use App\Models\Category; // Renamed SeriesCategory to Category
use App\Models\Channel;
use App\Models\Group;    // Renamed ChannelGroup to Group
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\User;
use App\Models\Season;   // Added Season
use App\Models\Episode;  // Added Episode
use App\Enums\ChannelLogoType; // Added
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL; // Added
use Illuminate\Support\Str;
use Tests\TestCase;
use Carbon\Carbon; // Added

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
        $this->username = 'testuser_' . Str::random(5); // Unique username for auth
        $this->password = 'testpass';

        // Ensure only one PlaylistAuth is created for the combination
        PlaylistAuth::updateOrCreate(
            ['playlist_id' => $this->playlist->id, 'username' => $this->username],
            [
                'password' => $this->password, // In real app, hash passwords if not already
                'is_enabled' => true,
            ]
        );

        // Mock URL::asset to prevent issues with asset versioning in tests
        // Check if already mocked to avoid conflicts if setUp is called multiple times by test runner
        if (!URL::hasMacro('asset')) { // A simple check; better might be a static flag
            URL::shouldReceive('asset')->andReturnUsing(function ($path) {
                return 'http://localhost/' . ltrim($path, '/');
            })->byDefault();
        }
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
        return route('playlist.xtream.api', ['uuid' => $this->playlist->uuid]) . '?' . http_build_query($queryParams);
    }

    // Standard setup for authenticated requests to panel action (from existing tests, slightly adapted)
    private function setupAuthenticatedPanelRequest(User &$user = null, Playlist &$playlist = null, array $playlistAuthCredentials = [])
    {
        $user = $user ?? $this->user; // Use class property if not provided
        $playlist = $playlist ?? $this->playlist; // Use class property if not provided

        $authUsername = $playlistAuthCredentials['username'] ?? $this->username;
        $authPassword = $playlistAuthCredentials['password'] ?? $this->password;

        // PlaylistAuth is already created in setUp generally, but this allows override for specific panel tests
        if (!PlaylistAuth::where('playlist_id', $playlist->id)->where('username', $authUsername)->exists()) {
             PlaylistAuth::factory()->for($playlist)->create([
                 'username' => $authUsername,
                 'password' => $authPassword,
                 'is_enabled' => true,
             ]);
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
            'user_info', 'server_info', 'available_channels', 'series', 'categories',
        ]);
        $response->assertJsonStructure([
            'user_info' => [
                'username', 'password', 'message', 'auth', 'status',
                'exp_date', 'is_trial', 'active_cons', 'created_at',
                'max_connections', 'allowed_output_formats',
            ],
        ]);
        $response->assertJsonPath('user_info.status', 'Active');
        $response->assertJsonStructure([
            'server_info' => [
                'url', 'port', 'https_port', 'rtmp_port', 'server_protocol',
                'timezone', 'server_software', 'timestamp_now', 'time_now',
            ],
        ]);
        $response->assertJsonPath('server_info.server_software', 'MediaFlow Xtream API');
    }

    public function test_panel_action_with_invalid_playlist_auth_returns_unauthorized(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        PlaylistAuth::factory()->for($playlist)->create([ // Valid auth created
            'username' => 'correct_user',
            'password' => 'correct_password',
            'is_enabled' => true,
        ]);

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
        PlaylistAuth::factory()->for($playlist)->create(['is_enabled' => false]); // Ensure PlaylistAuth not used

        $response = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid,
            'action' => 'panel',
            'username' => 'm3ue',
            'password' => $plainPassword,
        ]));

        $response->assertOk();
        $response->assertJsonPath('user_info.username', 'm3ue');
        $response->assertJsonStructure(['user_info', 'server_info', 'available_channels', 'series', 'categories']);
    }

    public function test_panel_action_with_m3ue_user_and_incorrect_password_returns_unauthorized(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct_password')]);
        $playlist = Playlist::factory()->for($user)->create();
        PlaylistAuth::factory()->for($playlist)->create(['is_enabled' => false]);

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
            'uuid' => Str::uuid()->toString(), 'action' => 'panel', 'username' => 'any', 'password' => 'any',
        ]));
        $response->assertStatus(404);
        $response->assertJson(['error' => 'Playlist not found']);
    }

    public function test_panel_action_with_missing_credentials_returns_unauthorized(): void
    {
        $playlist = Playlist::factory()->create(); // User automatically created by Playlist factory if not specified

        $responseMissingUser = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid, 'action' => 'panel', 'password' => 'test',
        ]));
        $responseMissingUser->assertStatus(401)->assertJson(['error' => 'Unauthorized - Missing credentials']);

        $responseMissingPass = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid, 'action' => 'panel', 'username' => 'test',
        ]));
        $responseMissingPass->assertStatus(401)->assertJson(['error' => 'Unauthorized - Missing credentials']);
    }

    public function test_panel_action_contains_playlist_channels(): void
    {
        $user = null; $playlist = null; // Passed by reference to setup
        $group = Group::factory()->create(['name' => 'Test Group']); // ID will be auto-generated

        // This will be passed by reference and populated by setupAuthenticatedPanelRequest
        $this->setupAuthenticatedPanelRequest($user, $playlist);

        $channel1 = Channel::factory()->for($playlist)->for($group)->create(['title' => 'Channel 1', 'enabled' => true]);
        Channel::factory()->for($playlist)->for($group)->create(['title' => 'Channel 2', 'enabled' => true]);
        Channel::factory()->for($playlist)->create(['title' => 'Channel Disabled', 'enabled' => false]); // Should not appear

        // Re-fetch after adding channels
        $response = $this->setupAuthenticatedPanelRequest($user, $playlist);

        $response->assertOk();
        $response->assertJsonCount(2, 'available_channels'); // Only enabled channels
        $response->assertJsonPath('available_channels.0.name', 'Channel 1');
        $response->assertJsonPath('available_channels.0.stream_id', $channel1->id); // stream_id is int
        $response->assertJsonPath('available_channels.0.category_id', (string)$group->id); // category_id is string

        $response->assertJsonFragment([ // Check category presence
            'category_id' => (string)$group->id,
            'category_name' => 'Test Group',
        ]);
    }

    public function test_panel_action_contains_playlist_series(): void
    {
        $user = null; $playlist = null;
        $seriesCategory = Category::factory()->create(['name' => 'Test Series Category']);

        $this->setupAuthenticatedPanelRequest($user, $playlist);

        $seriesA = Series::factory()->for($playlist)->for($seriesCategory)->create(['name' => 'Series A', 'enabled' => true]);
        Series::factory()->for($playlist)->for($seriesCategory)->create(['name' => 'Series B', 'enabled' => true]);
        Series::factory()->for($playlist)->create(['name' => 'Series Disabled', 'enabled' => false]); // Should not appear

        // Re-fetch after adding series
        $response = $this->setupAuthenticatedPanelRequest($user, $playlist);

        $response->assertOk();
        $response->assertJsonCount(2, 'series'); // Only enabled series
        $response->assertJsonPath('series.0.name', 'Series A');
        $response->assertJsonPath('series.0.series_id', $seriesA->id); // series_id is int
        $response->assertJsonPath('series.0.category_id', (string)$seriesCategory->id); // category_id is string

        $response->assertJsonFragment([
            'category_id' => (string)$seriesCategory->id,
            'category_name' => 'Test Series Category',
        ]);
    }

    public function test_panel_action_contains_mixed_categories(): void
    {
        $user = null; $playlist = null; // Passed by ref
        // Use class properties for user and playlist if already set up by main setUp
        $this->setUp(); // Ensure base setup is run if test is run in isolation
        $user = $this->user;
        $playlist = $this->playlist;


        $liveGroup = Group::factory()->create(['name' => 'Live Group']);
        $vodCategory = Category::factory()->create(['name' => 'VOD Category']);

        // Pass the specific user and playlist to ensure context
        $this->setupAuthenticatedPanelRequest($user, $playlist);

        Channel::factory()->for($playlist)->for($liveGroup)->create(['enabled' => true]);
        Series::factory()->for($playlist)->for($vodCategory)->create(['enabled' => true]);

        $response = $this->setupAuthenticatedPanelRequest($user, $playlist);

        $response->assertOk();
        $response->assertJsonFragment(['category_name' => 'Live Group', 'category_id' => (string)$liveGroup->id]);
        $response->assertJsonFragment(['category_name' => 'VOD Category', 'category_id' => (string)$vodCategory->id]);
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
                'num', 'name', 'stream_type', 'stream_id', 'stream_icon', 'epg_channel_id',
                'added', 'category_id', 'tv_archive', 'direct_source', 'tv_archive_duration'
            ]
        ]);
        // Check specific icon for channel 1
        $this->assertEquals('http://localhost/icon1.png', $response->json('0.stream_icon'));
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
        $enabledSeries1 = Series::factory()->for($this->playlist)->for($category)->create(['enabled' => true, 'name' => 'Enabled Series 1', 'cover_image' => 'cover1.jpg']);
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
                'num', 'name', 'series_id', 'cover', 'plot', 'cast', 'director', 'genre',
                'releaseDate', 'last_modified', 'rating', 'rating_5based',
                'backdrop_path', 'youtube_trailer', 'episode_run_time', 'category_id'
            ]
        ]);
        $this->assertEquals('http://localhost/cover1.jpg', $response->json('0.cover'));
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
                    ->state(function (array $attributes, Season $season) {
                        return ['container_extension' => 'mp4', 'title' => 'Episode Title '.Str::random(3)];
                    }), 'episodes'), 'seasons')
            ->create(['enabled' => true, 'name' => 'Test Series with Episodes', 'cover_image' => 'series_cover.jpg']);

        $response = $this->getJson($this->getXtreamApiUrl('get_vod_info', ['vod_id' => $series->id]));

        $response->assertStatus(200)
            ->assertJsonPath('info.name', $series->name)
            ->assertJsonPath('info.cover', 'http://localhost/series_cover.jpg')
            ->assertJsonPath('movie_data.name', $series->name)
            ->assertJsonCount(2, 'episodes');

        $firstSeason = $series->seasons()->orderBy('season_number')->first();
        $firstSeasonNumber = $firstSeason->season_number;
        $firstEpisode = $firstSeason->episodes()->orderBy('episode_number')->first();

        $response->assertJsonPath("episodes.{$firstSeasonNumber}.0.id", (string)$firstEpisode->id);
        $response->assertJsonPath("episodes.{$firstSeasonNumber}.0.title", $firstEpisode->title);
        $response->assertJsonPath("episodes.{$firstSeasonNumber}.0.container_extension", $firstEpisode->container_extension ?? 'mp4');
        $response->assertJsonPath("episodes.{$firstSeasonNumber}.0.stream_id", $firstEpisode->id); // Assuming stream_id is episode_id

        $expectedUrlPath = "/series/{$this->playlist->uuid}/{$this->username}/{$this->password}/{$series->id}-{$firstEpisode->id}.{$firstEpisode->container_extension}";
        $actualDirectSource = $response->json("episodes.{$firstSeasonNumber}.0.direct_source");
        $this->assertNotNull($actualDirectSource, "Direct source URL is null.");
        $this->assertStringContainsString($expectedUrlPath, $actualDirectSource);


        $response->assertJsonStructure([
            'info' => ['name', 'cover', 'plot', 'cast', 'director', 'genre', 'releaseDate', 'last_modified', 'rating', 'rating_5based', 'backdrop_path', 'youtube_trailer', 'episode_run_time', 'category_id'],
            'episodes' => [
                '*' => [
                    '*' => [
                        'id', 'episode_num', 'title', 'container_extension',
                        'info' => ['movie_image', 'plot', 'duration_secs', 'duration', 'video', 'audio', 'bitrate', 'rating'],
                        'added', 'season', 'stream_id', 'direct_source'
                    ]
                ]
            ],
            'movie_data' => ['stream_id', 'name', 'title', 'year', 'episode_run_time', 'plot', 'cast', 'director', 'genre', 'releaseDate', 'last_modified', 'rating', 'rating_5based', 'cover_big', 'youtube_trailer', 'backdrop_path']
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
                'is_movie' => true,
                'container_extension' => 'mkv',
                'plot_summary' => 'A great movie plot.',
                'cover_image' => 'movie_cover.jpg',
            ]);

        $response = $this->getJson($this->getXtreamApiUrl('get_vod_info', ['vod_id' => $movieSeries->id]));

        $response->assertStatus(200)
            ->assertJsonPath('info.name', $movieSeries->name)
            ->assertJsonPath('info.cover', 'http://localhost/movie_cover.jpg')
            ->assertJsonPath('movie_data.name', $movieSeries->name);

        $response->assertJsonCount(1, 'episodes.1');
        $response->assertJsonPath('episodes.1.0.id', (string)$movieSeries->id);
        $response->assertJsonPath('episodes.1.0.title', $movieSeries->name);
        $response->assertJsonPath('episodes.1.0.container_extension', $movieSeries->container_extension);
        $response->assertJsonPath('episodes.1.0.stream_id', $movieSeries->id);
        $response->assertJsonPath('episodes.1.0.info.plot', $movieSeries->plot_summary);
        $response->assertJsonPath('episodes.1.0.info.movie_image', 'http://localhost/movie_cover.jpg');

        $expectedUrlPath = "/series/{$this->playlist->uuid}/{$this->username}/{$this->password}/{$movieSeries->id}.{$movieSeries->container_extension}";
        $actualDirectSource = $response->json('episodes.1.0.direct_source');
        $this->assertNotNull($actualDirectSource, "Direct source URL is null for movie.");
        $this->assertStringContainsString($expectedUrlPath, $actualDirectSource);
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
}
