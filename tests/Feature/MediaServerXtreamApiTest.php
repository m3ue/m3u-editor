<?php

namespace Tests\Feature;

use App\Enums\PlaylistSourceType;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaServerXtreamApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Playlist $playlist;
    protected PlaylistAuth $auth;
    protected MediaServerIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Create media server integration
        $this->integration = MediaServerIntegration::create([
            'name' => 'Test Emby',
            'type' => 'emby',
            'host' => '192.168.1.100',
            'port' => 8096,
            'api_key' => 'test-api-key',
            'ssl' => false,
            'enabled' => true,
            'user_id' => $this->user->id,
            'import_movies' => true,
            'import_series' => true,
        ]);

        // Create playlist for the integration
        $this->playlist = Playlist::create([
            'name' => 'Test Emby Playlist',
            'url' => 'http://192.168.1.100:8096',
            'user_id' => $this->user->id,
            'source_type' => PlaylistSourceType::Emby,
            'xtream' => true,
        ]);

        $this->integration->update(['playlist_id' => $this->playlist->id]);

        // Create Xtream auth
        $this->auth = PlaylistAuth::create([
            'playlist_id' => $this->playlist->id,
            'username' => 'testuser',
            'password' => 'testpass',
        ]);
    }

    /** @test */
    public function it_lists_vod_categories_from_media_server()
    {
        // Create VOD group (movie category)
        $group = Group::create([
            'name' => 'Action',
            'name_internal' => 'Action',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'type' => 'vod',
        ]);

        // Create VOD channel (movie)
        Channel::create([
            'name' => 'Test Movie',
            'title' => 'Test Movie',
            'url' => 'http://192.168.1.100:8096/Videos/1234/stream.mp4?static=true',
            'logo' => 'http://192.168.1.100:8096/Items/1234/Images/Primary',
            'group' => 'Action',
            'group_id' => $group->id,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'enabled' => true,
            'is_vod' => true,
            'container_extension' => 'mp4',
            'year' => 2023,
            'rating' => 8.5,
            'info' => [
                'media_server_id' => '1234',
                'media_server_type' => 'emby',
                'plot' => 'An exciting action movie',
                'director' => 'John Director',
                'actors' => 'Jane Actor, Bob Star',
            ],
        ]);

        $response = $this->getJson("/player_api.php?username={$this->auth->username}&password={$this->auth->password}&action=get_vod_categories");

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'category_name' => 'Action',
            ]);
    }

    /** @test */
    public function it_lists_vod_streams_from_media_server()
    {
        $group = Group::create([
            'name' => 'Action',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'type' => 'vod',
        ]);

        $channel = Channel::create([
            'name' => 'Test Movie',
            'title' => 'Test Movie',
            'url' => 'http://192.168.1.100:8096/Videos/1234/stream.mp4?static=true',
            'logo' => 'http://192.168.1.100:8096/Items/1234/Images/Primary',
            'group' => 'Action',
            'group_id' => $group->id,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'enabled' => true,
            'is_vod' => true,
            'container_extension' => 'mp4',
            'year' => 2023,
            'rating' => 8.5,
            'info' => [
                'plot' => 'An exciting action movie',
                'director' => 'John Director',
                'actors' => 'Jane Actor, Bob Star',
                'duration_secs' => 7200,
                'duration' => '02:00:00',
            ],
        ]);

        $response = $this->getJson("/player_api.php?username={$this->auth->username}&password={$this->auth->password}&action=get_vod_streams&category_id={$group->id}");

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'name' => 'Test Movie',
                'stream_id' => $channel->id,
                'container_extension' => 'mp4',
            ]);
    }

    /** @test */
    public function it_returns_vod_info_with_metadata()
    {
        $group = Group::create([
            'name' => 'Action',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'type' => 'vod',
        ]);

        $channel = Channel::create([
            'name' => 'Test Movie',
            'title' => 'Test Movie',
            'url' => 'http://192.168.1.100:8096/Videos/1234/stream.mp4?static=true',
            'logo' => 'http://192.168.1.100:8096/Items/1234/Images/Primary',
            'group' => 'Action',
            'group_id' => $group->id,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'enabled' => true,
            'is_vod' => true,
            'container_extension' => 'mp4',
            'year' => 2023,
            'rating' => 8.5,
            'info' => [
                'plot' => 'An exciting action movie',
                'description' => 'An exciting action movie',
                'director' => 'John Director',
                'actors' => 'Jane Actor, Bob Star',
                'cast' => 'Jane Actor, Bob Star',
                'genre' => 'Action, Thriller',
                'duration_secs' => 7200,
                'duration' => '02:00:00',
                'episode_run_time' => 120,
            ],
        ]);

        $response = $this->getJson("/player_api.php?username={$this->auth->username}&password={$this->auth->password}&action=get_vod_info&vod_id={$channel->id}");

        $response->assertOk()
            ->assertJsonPath('info.plot', 'An exciting action movie')
            ->assertJsonPath('info.description', 'An exciting action movie')
            ->assertJsonPath('info.director', 'John Director')
            ->assertJsonPath('info.actors', 'Jane Actor, Bob Star')
            ->assertJsonPath('info.cast', 'Jane Actor, Bob Star')
            ->assertJsonPath('info.genre', 'Action, Thriller')
            ->assertJsonPath('info.duration_secs', 7200)
            ->assertJsonPath('info.duration', '02:00:00')
            ->assertJsonPath('movie_data.year', 2023)
            ->assertJsonPath('movie_data.name', 'Test Movie')
            ->assertJsonPath('movie_data.container_extension', 'mp4');
    }

    /** @test */
    public function it_lists_series_categories_from_media_server()
    {
        $category = Category::create([
            'name' => 'Drama',
            'name_internal' => 'Drama',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
        ]);

        $series = Series::create([
            'name' => 'Test Series',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'category_id' => $category->id,
            'enabled' => true,
        ]);

        $response = $this->getJson("/player_api.php?username={$this->auth->username}&password={$this->auth->password}&action=get_series_categories");

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'category_name' => 'Drama',
            ]);
    }

    /** @test */
    public function it_lists_series_from_media_server()
    {
        $category = Category::create([
            'name' => 'Drama',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
        ]);

        $series = Series::create([
            'name' => 'Test Series',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'category_id' => $category->id,
            'source_category_id' => $category->id,
            'enabled' => true,
            'cover' => 'http://192.168.1.100:8096/Items/5678/Images/Primary',
            'plot' => 'A compelling drama series',
            'genre' => 'Drama, Mystery',
            'release_date' => 2022,
            'rating' => 9.0,
        ]);

        $response = $this->getJson("/player_api.php?username={$this->auth->username}&password={$this->auth->password}&action=get_series&category_id={$category->id}");

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'name' => 'Test Series',
                'series_id' => $series->id,
                'cover' => 'http://192.168.1.100:8096/Items/5678/Images/Primary',
                'plot' => 'A compelling drama series',
                'genre' => 'Drama, Mystery',
                'releaseDate' => '2022',
                'rating' => '9.0',
            ]);
    }

    /** @test */
    public function it_returns_series_info_with_seasons_and_episodes()
    {
        $category = Category::create([
            'name' => 'Drama',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
        ]);

        $series = Series::create([
            'name' => 'Test Series',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'category_id' => $category->id,
            'source_category_id' => $category->id,
            'enabled' => true,
            'plot' => 'A compelling drama series',
        ]);

        $season = Season::create([
            'name' => 'Season 1',
            'series_id' => $series->id,
            'season_number' => 1,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'cover' => 'http://192.168.1.100:8096/Items/9999/Images/Primary',
        ]);

        $episode = Episode::create([
            'title' => 'Pilot',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'series_id' => $series->id,
            'season_id' => $season->id,
            'episode_num' => 1,
            'season' => 1,
            'url' => 'http://192.168.1.100:8096/Videos/7890/stream.mkv?static=true',
            'container_extension' => 'mkv',
            'enabled' => true,
            'plot' => 'The first episode',
            'cover' => 'http://192.168.1.100:8096/Items/7890/Images/Primary',
            'info' => [
                'duration_secs' => 3600,
                'duration' => '01:00:00',
            ],
        ]);

        $response = $this->getJson("/player_api.php?username={$this->auth->username}&password={$this->auth->password}&action=get_series_info&series_id={$series->id}");

        $response->assertOk()
            ->assertJsonPath('info.name', 'Test Series')
            ->assertJsonPath('info.plot', 'A compelling drama series')
            ->assertJsonPath('seasons.0.season_number', 1)
            ->assertJsonPath('seasons.0.name', 'Season 1')
            ->assertJsonCount(1, 'episodes.1') // Season 1 episodes
            ->assertJsonPath('episodes.1.0.title', 'Pilot')
            ->assertJsonPath('episodes.1.0.episode_num', 1)
            ->assertJsonPath('episodes.1.0.container_extension', 'mkv')
            ->assertJsonPath('episodes.1.0.plot', 'The first episode')
            ->assertJsonPath('episodes.1.0.info.duration', '01:00:00');
    }

    /** @test */
    public function it_handles_direct_stream_urls_for_vod()
    {
        $group = Group::create([
            'name' => 'Action',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'type' => 'vod',
        ]);

        $channel = Channel::create([
            'name' => 'Test Movie',
            'url' => 'http://192.168.1.100:8096/Videos/1234/stream.mp4?static=true&api_key=test-key',
            'group_id' => $group->id,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'enabled' => true,
            'is_vod' => true,
            'container_extension' => 'mp4',
        ]);

        $response = $this->getJson("/player_api.php?username={$this->auth->username}&password={$this->auth->password}&action=get_vod_info&vod_id={$channel->id}");

        $response->assertOk();

        $movieData = $response->json('movie_data');
        $this->assertStringContainsString('movie', $movieData['direct_source']);
        $this->assertStringContainsString((string)$channel->id, $movieData['direct_source']);
        $this->assertStringContainsString('.mp4', $movieData['direct_source']);
    }

    /** @test */
    public function it_handles_direct_stream_urls_for_episodes()
    {
        $category = Category::create([
            'name' => 'Drama',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
        ]);

        $series = Series::create([
            'name' => 'Test Series',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'category_id' => $category->id,
            'enabled' => true,
        ]);

        $season = Season::create([
            'name' => 'Season 1',
            'series_id' => $series->id,
            'season_number' => 1,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
        ]);

        $episode = Episode::create([
            'title' => 'Pilot',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'series_id' => $series->id,
            'season_id' => $season->id,
            'episode_num' => 1,
            'season' => 1,
            'url' => 'http://192.168.1.100:8096/Videos/7890/stream.mkv?static=true&api_key=test-key',
            'container_extension' => 'mkv',
            'enabled' => true,
        ]);

        $response = $this->getJson("/player_api.php?username={$this->auth->username}&password={$this->auth->password}&action=get_series_info&series_id={$series->id}");

        $response->assertOk();

        $episodeData = $response->json('episodes.1.0');
        $this->assertStringContainsString('series', $episodeData['direct_source']);
        $this->assertStringContainsString((string)$episode->id, $episodeData['direct_source']);
        $this->assertStringContainsString('.mkv', $episodeData['direct_source']);
    }

    /** @test */
    public function it_only_returns_enabled_content()
    {
        $group = Group::create([
            'name' => 'Action',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'type' => 'vod',
        ]);

        // Enabled channel
        Channel::create([
            'name' => 'Enabled Movie',
            'url' => 'http://example.com/movie1.mp4',
            'group_id' => $group->id,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'enabled' => true,
            'is_vod' => true,
        ]);

        // Disabled channel
        Channel::create([
            'name' => 'Disabled Movie',
            'url' => 'http://example.com/movie2.mp4',
            'group_id' => $group->id,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'enabled' => false,
            'is_vod' => true,
        ]);

        $response = $this->getJson("/player_api.php?username={$this->auth->username}&password={$this->auth->password}&action=get_vod_streams&category_id={$group->id}");

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Enabled Movie'])
            ->assertJsonMissing(['name' => 'Disabled Movie']);
    }
}
