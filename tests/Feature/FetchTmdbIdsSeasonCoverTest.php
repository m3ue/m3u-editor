<?php

use App\Jobs\FetchTmdbIds;
use App\Models\Category;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use Illuminate\Support\Facades\Event;

class FakeTmdbService extends TmdbService
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function searchTvSeries(string $name, ?int $year = null, bool $tryFallback = true): ?array
    {
        return [
            'tmdb_id' => 123,
            'tvdb_id' => 456,
            'imdb_id' => 'tt1234567',
            'confidence' => 100,
            'name' => $name,
        ];
    }

    public function getTvSeriesDetails(int $tmdbId): ?array
    {
        return [
            'poster_url' => null,
            'overview' => null,
            'genres' => null,
            'first_air_date' => null,
            'vote_average' => null,
            'backdrop_url' => null,
        ];
    }

    public function getAllSeasons(int $tmdbId): array
    {
        return [
            ['season_number' => 1],
        ];
    }

    public function getSeasonDetails(int $tmdbId, int $seasonNumber): ?array
    {
        return [
            'poster_path' => '/poster.jpg',
            'episodes' => [
                [
                    'id' => 999,
                    'episode_number' => 1,
                    'name' => 'Episode 1',
                    'overview' => 'Plot',
                    'still_path' => '/still.jpg',
                    'air_date' => '2024-01-01',
                    'vote_average' => 8.2,
                ],
            ],
        ];
    }
}

class FakeFetchTmdbIds extends FetchTmdbIds
{
    protected function sendCompletionNotification(): void {}

    protected function notifyUser(string $title, string $body, string $type = 'success'): void {}
}

test('it updates season cover images from tmdb when fetching episode data', function () {
    Event::fake();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $series = Series::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Test Series',
        'metadata' => [],
    ]);

    $season = Season::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'category_id' => $category->id,
        'series_id' => $series->id,
        'season_number' => 1,
        'cover' => null,
        'cover_big' => null,
    ]);

    Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'season' => 1,
        'episode_num' => 1,
        'cover' => null,
        'info' => [],
    ]);

    $job = new FakeFetchTmdbIds(
        vodChannelIds: null,
        seriesIds: [$series->id],
        vodPlaylistId: null,
        seriesPlaylistId: null,
        allVodPlaylists: false,
        allSeriesPlaylists: false,
        overwriteExisting: false,
        user: $user
    );

    $job->handle(new FakeTmdbService);

    $season->refresh();

    expect($season->cover)->toBe('https://image.tmdb.org/t/p/w500/poster.jpg');
    expect($season->cover_big)->toBe('https://image.tmdb.org/t/p/original/poster.jpg');
});
