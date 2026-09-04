<?php

/**
 * Regression test for the issue #1482 one-time cleanup migration
 * (2026_09_04_120000_cleanup_series_with_no_source_series_id).
 *
 * Proves the deletion query hits only the genuinely-malformed Xtream import case
 * and leaves every legitimate NULL-source_series_id series alone: AIOStreams
 * (is_custom), DVR-generated, and media server (belt-and-suspenders check on
 * metadata->media_server_id, even though those always carry a non-null
 * source_series_id in practice).
 */

use App\Models\Category;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;

function runSeriesCleanupMigration(): void
{
    $migration = require database_path('migrations/2026_09_04_120000_cleanup_series_with_no_source_series_id.php');
    $migration->up();
}

it('deletes a malformed Xtream series with no source_series_id, cascading its episodes and seasons', function () {
    $user = User::factory()->create();
    $playlist = Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create());
    $category = Category::factory()->for($playlist)->for($user)->create();

    $broken = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'category_id' => $category->id,
        'source_series_id' => null,
        'is_custom' => false,
        'import_batch_no' => 'a-real-sync-batch',
    ]);
    $season = Season::factory()->for($broken)->create();
    $episode = Episode::factory()->for($broken)->create(['season_id' => $season->id]);

    runSeriesCleanupMigration();

    expect(Series::find($broken->id))->toBeNull();
    expect(Season::find($season->id))->toBeNull();
    expect(Episode::find($episode->id))->toBeNull();
});

it('leaves an AIOStreams (is_custom) series with no source_series_id alone', function () {
    $user = User::factory()->create();
    $playlist = Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create());
    $category = Category::factory()->for($playlist)->for($user)->create();

    $aio = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'category_id' => $category->id,
        'source_series_id' => null,
        'is_custom' => true,
    ]);

    runSeriesCleanupMigration();

    expect(Series::find($aio->id))->not->toBeNull();
});

it('leaves a DVR-generated series with no source_series_id alone', function () {
    $user = User::factory()->create();
    $playlist = Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create());
    $category = Category::factory()->for($playlist)->for($user)->create();

    $dvr = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'category_id' => $category->id,
        'source_series_id' => null,
        'is_custom' => false,
        'import_batch_no' => 'dvr',
    ]);

    runSeriesCleanupMigration();

    expect(Series::find($dvr->id))->not->toBeNull();
});

it('leaves a series tied to a media server alone even if its source_series_id were somehow null', function () {
    $user = User::factory()->create();
    $playlist = Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create());
    $category = Category::factory()->for($playlist)->for($user)->create();

    $mediaServer = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'category_id' => $category->id,
        'source_series_id' => null,
        'is_custom' => false,
        'import_batch_no' => 'media-server-sync-batch',
        'metadata' => ['media_server_id' => 'abc123'],
    ]);

    runSeriesCleanupMigration();

    expect(Series::find($mediaServer->id))->not->toBeNull();
});
