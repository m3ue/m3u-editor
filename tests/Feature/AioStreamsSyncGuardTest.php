<?php

use App\Jobs\SyncMediaServer;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Guards against the exact regression this feature depends on: is_custom
 * (AIOStreams-added) Series/Season/Episode rows must survive
 * SyncMediaServer's stale-record cleanup, just like is_custom Channels
 * already do. Without the is_custom guard added to cleanupStaleRecords(),
 * a no-op sync (e.g. AIOStreams, whose fetchSeries() always returns empty)
 * would silently wipe any series added via the "Add to Library" flow.
 */
it('does not delete is_custom series/season/episode/channel rows during stale-record cleanup', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->create(['user_id' => $user->id]);
    $integration = MediaServerIntegration::create([
        'user_id' => $user->id,
        'name' => 'Test Integration',
        'type' => 'aiostreams',
        'enabled' => true,
        'import_movies' => true,
        'import_series' => true,
        'playlist_id' => $playlist->id,
    ]);
    $category = Category::factory()->create(['user_id' => $user->id, 'playlist_id' => $playlist->id]);

    // Stale, non-custom rows (simulating provider-synced content from a previous run).
    $staleChannel = Channel::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'is_custom' => false,
        'import_batch_no' => 'old-batch',
    ]);
    $staleSeries = Series::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'category_id' => $category->id,
        'is_custom' => false,
        'import_batch_no' => 'old-batch',
    ]);
    $staleSeason = Season::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'category_id' => $category->id,
        'series_id' => $staleSeries->id,
        'is_custom' => false,
        'import_batch_no' => 'old-batch',
    ]);
    $staleEpisode = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $staleSeries->id,
        'season_id' => $staleSeason->id,
        'is_custom' => false,
        'import_batch_no' => 'old-batch',
    ]);

    // Custom (AIOStreams-added) rows with an equally stale batch number — these must survive.
    $customChannel = Channel::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'is_custom' => true,
        'import_batch_no' => 'old-batch',
    ]);
    $customSeries = Series::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'category_id' => $category->id,
        'is_custom' => true,
        'import_batch_no' => 'old-batch',
    ]);
    $customSeason = Season::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'category_id' => $category->id,
        'series_id' => $customSeries->id,
        'is_custom' => true,
        'import_batch_no' => 'old-batch',
    ]);
    $customEpisode = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $customSeries->id,
        'season_id' => $customSeason->id,
        'is_custom' => true,
        'import_batch_no' => 'old-batch',
    ]);

    $job = new SyncMediaServer($integration->id);

    $method = new ReflectionMethod(SyncMediaServer::class, 'cleanupStaleRecords');
    $method->setAccessible(true);
    $method->invoke($job, $integration, $playlist);

    expect(Channel::find($staleChannel->id))->toBeNull()
        ->and(Series::find($staleSeries->id))->toBeNull()
        ->and(Season::find($staleSeason->id))->toBeNull()
        ->and(Episode::find($staleEpisode->id))->toBeNull()
        ->and(Channel::find($customChannel->id))->not->toBeNull()
        ->and(Series::find($customSeries->id))->not->toBeNull()
        ->and(Season::find($customSeason->id))->not->toBeNull()
        ->and(Episode::find($customEpisode->id))->not->toBeNull();
});
