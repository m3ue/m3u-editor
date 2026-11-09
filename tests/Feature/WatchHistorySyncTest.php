<?php

/**
 * On-demand import / export between a Playlist Auth's watch progress and a
 * Trakt / SIMKL account.
 */

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use App\Models\WatchHistoryIntegration;
use App\Models\WatchHistorySyncState;
use App\Services\WatchHistory\WatchHistorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->playlistAuth = PlaylistAuth::create([
        'name' => 'Den',
        'username' => 'syncuser',
        'password' => 'syncpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlistAuth->assignTo($this->playlist);

    $this->trakt = WatchHistoryIntegration::factory()->for($this->user)->create();
    $this->trakt->playlistAuths()->attach($this->playlistAuth);

    $this->movie = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'tmdb_id' => 603,
        'info' => ['duration_secs' => 8160],
    ]);
    $this->series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => 1396,
    ]);
    $this->episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'season' => 1,
        'episode_num' => 2,
        'info' => ['duration_secs' => 2820],
    ]);
});

function fakeTraktHistory(array $watchedMovies = [], array $watchedShows = [], array $playback = []): void
{
    Http::fake([
        'api.trakt.tv/sync/watched/movies' => Http::response($watchedMovies),
        'api.trakt.tv/sync/watched/shows' => Http::response($watchedShows),
        'api.trakt.tv/sync/playback' => Http::response($playback),
        'api.trakt.tv/sync/history' => Http::response(['added' => ['movies' => 1, 'episodes' => 1]], 201),
    ]);
}

it('imports watched titles and resume points into a new viewer for the auth', function () {
    fakeTraktHistory(
        watchedMovies: [
            ['last_watched_at' => '2026-10-01T20:00:00.000Z', 'movie' => ['title' => 'The Matrix', 'ids' => ['tmdb' => 603]]],
            ['last_watched_at' => '2026-10-02T20:00:00.000Z', 'movie' => ['title' => 'Not Here', 'ids' => ['tmdb' => 999999]]],
        ],
        playback: [
            ['progress' => 25.0, 'paused_at' => '2026-10-05T20:00:00.000Z', 'type' => 'episode',
                'episode' => ['season' => 1, 'number' => 2], 'show' => ['title' => 'Breaking Bad', 'ids' => ['tmdb' => 1396]]],
        ],
    );

    $stats = app(WatchHistorySyncService::class)->run($this->trakt, $this->playlistAuth, 'import');

    expect($stats)->toMatchArray(['imported' => 2, 'unmatched' => 1, 'exported' => 0]);

    $viewer = PlaylistViewer::where('playlist_auth_id', $this->playlistAuth->id)->sole();
    $movieRow = ViewerWatchProgress::where('playlist_viewer_id', $viewer->id)->where('content_type', 'vod')->sole();
    $episodeRow = ViewerWatchProgress::where('playlist_viewer_id', $viewer->id)->where('content_type', 'episode')->sole();

    expect($movieRow)
        ->stream_id->toBe($this->movie->id)
        ->completed->toBeTrue()
        ->and($episodeRow)
        ->stream_id->toBe($this->episode->id)
        ->completed->toBeFalse()
        ->position_seconds->toBe(705)
        ->season_number->toBe(1)
        ->episode_number->toBe(2);

    // The account's UTC time is kept as the same moment in the app's timezone.
    expect($movieRow->last_watched_at->equalTo(now()->parse('2026-10-01T20:00:00Z')))->toBeTrue();

    // Imported rows are recorded as already on the account.
    expect(WatchHistorySyncState::count())->toBe(2);
    expect($this->trakt->fresh()->last_synced_at)->not->toBeNull();
});

it('keeps local progress that is newer than the account\'s', function () {
    $viewer = PlaylistViewer::factory()->create([
        'playlist_auth_id' => $this->playlistAuth->id,
        'viewerable_id' => $this->playlist->id,
    ]);
    ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewer->id,
        'content_type' => 'vod',
        'stream_id' => $this->movie->id,
        'position_seconds' => 100,
        'duration_seconds' => 8160,
        'completed' => false,
        'last_watched_at' => now()->parse('2026-10-10 12:00:00'),
    ]);
    fakeTraktHistory(watchedMovies: [
        ['last_watched_at' => '2026-10-01T20:00:00.000Z', 'movie' => ['ids' => ['tmdb' => 603]]],
    ]);

    $stats = app(WatchHistorySyncService::class)->run($this->trakt, $this->playlistAuth, 'import');

    expect($stats['unchanged'])->toBe(1)
        ->and(ViewerWatchProgress::sole()->completed)->toBeFalse();
});

it('replaces local movie and episode progress with the account\'s', function () {
    $viewer = PlaylistViewer::factory()->create([
        'playlist_auth_id' => $this->playlistAuth->id,
        'viewerable_id' => $this->playlist->id,
    ]);
    ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewer->id,
        'content_type' => 'episode',
        'stream_id' => $this->episode->id,
        'position_seconds' => 100,
        'completed' => true,
        'last_watched_at' => now(),
    ]);
    $live = ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewer->id,
        'content_type' => 'live',
        'stream_id' => 12345,
        'last_watched_at' => now(),
    ]);
    fakeTraktHistory(watchedMovies: [
        ['last_watched_at' => '2026-10-01T20:00:00.000Z', 'movie' => ['ids' => ['tmdb' => 603]]],
    ]);

    $stats = app(WatchHistorySyncService::class)->run($this->trakt, $this->playlistAuth, 'replace');

    expect($stats)->toMatchArray(['cleared' => 1, 'imported' => 1]);
    expect(ViewerWatchProgress::pluck('content_type')->sort()->values()->all())->toBe(['live', 'vod']);
    expect($live->fresh())->not->toBeNull();
});

it('exports watched titles the account does not have yet', function () {
    $viewer = PlaylistViewer::factory()->create([
        'playlist_auth_id' => $this->playlistAuth->id,
        'viewerable_id' => $this->playlist->id,
    ]);
    foreach ([['vod', $this->movie->id], ['episode', $this->episode->id]] as [$type, $streamId]) {
        ViewerWatchProgress::create([
            'playlist_viewer_id' => $viewer->id,
            'content_type' => $type,
            'stream_id' => $streamId,
            'completed' => true,
            'last_watched_at' => now()->parse('2026-10-03 21:00:00'),
        ]);
    }
    // The account already has the movie.
    fakeTraktHistory(watchedMovies: [
        ['last_watched_at' => '2026-10-01T20:00:00.000Z', 'movie' => ['ids' => ['tmdb' => 603]]],
    ]);

    app(WatchHistorySyncService::class)->run($this->trakt, $this->playlistAuth, 'export');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.trakt.tv/sync/history'
        && $request['movies'] === []
        && $request['shows'] === [[
            'ids' => ['tmdb' => 1396],
            'seasons' => [['number' => 1, 'episodes' => [['number' => 2, 'watched_at' => now()->parse('2026-10-03 21:00:00')->toIso8601ZuluString()]]]],
        ]]);
});

it('reads SIMKL watched shows and movies from all-items', function () {
    Http::fake([
        'api.simkl.com/sync/all-items*' => Http::response([
            'movies' => [
                ['status' => 'completed', 'last_watched_at' => '2026-09-01T10:00:00Z', 'movie' => ['title' => 'The Matrix', 'ids' => ['simkl' => 1, 'tmdb' => '603']]],
                ['status' => 'plantowatch', 'movie' => ['ids' => ['tmdb' => '604']]],
            ],
            'shows' => [
                ['show' => ['title' => 'Breaking Bad', 'ids' => ['tmdb' => '1396']], 'seasons' => [
                    ['number' => 1, 'episodes' => [['number' => 2, 'watched_at' => '2026-09-02T10:00:00Z']]],
                ]],
            ],
        ]),
        'api.simkl.com/sync/playback*' => Http::response([]),
    ]);
    $simkl = WatchHistoryIntegration::factory()->simkl()->for($this->user)->create();

    $stats = app(WatchHistorySyncService::class)->run($simkl, $this->playlistAuth, 'import');

    expect($stats['imported'])->toBe(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sync/all-items')
        && str_contains($request->url(), 'client_id=')
        && str_contains($request->url(), 'app-name=m3u-editor')
        && str_contains($request->url(), 'episode_watched_at=yes'));
});
