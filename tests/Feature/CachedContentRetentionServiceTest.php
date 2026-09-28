<?php

use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentRetentionService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory fires SyncPipelineService -> dispatch(ProcessM3uImport).
    Bus::fake();
});

/**
 * A Completed cached file for a fresh VOD channel on `$playlist`.
 */
function cachedMovieOn(Playlist $playlist, int $tmdbId = 550): CachedContentFile
{
    $channel = Channel::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'tmdb_id' => $tmdbId,
    ]);

    return CachedContentFile::factory()->completed()->forItem($channel)->create();
}

// --- evaluate(): stale = source item gone ---

it('evaluate() returns rows whose channel no longer exists', function () {
    $playlist = Playlist::factory()->create();
    $alive = cachedMovieOn($playlist);
    $stale = cachedMovieOn($playlist, 999);
    Channel::whereKey($stale->cacheable_id)->delete();

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->toBe([$stale->id])
        ->and($ids->all())->not->toContain($alive->id);
});

it('evaluate() keeps a row when the channel still exists, even if its TMDB id changed', function () {
    // Identity is the channel row, so enriching or correcting metadata
    // doesn't orphan the cached file.
    $playlist = Playlist::factory()->create();
    $file = cachedMovieOn($playlist);
    Channel::whereKey($file->cacheable_id)->update(['tmdb_id' => 12345]);

    expect(app(CachedContentRetentionService::class)->evaluate()->all())->toBeEmpty();
});

it('evaluate() returns rows whose episode no longer exists', function () {
    $playlist = Playlist::factory()->create();
    $series = Series::factory()->create(['user_id' => $playlist->user_id, 'playlist_id' => $playlist->id, 'tmdb_id' => 1399]);
    $episode = Episode::factory()->create(['user_id' => $playlist->user_id, 'playlist_id' => $playlist->id, 'series_id' => $series->id]);
    $file = CachedContentFile::factory()->completed()->forItem($episode)->create();
    $episode->delete();

    expect(app(CachedContentRetentionService::class)->evaluate()->all())->toBe([$file->id]);
});

it('evaluate() returns rows whose playlist was deleted (playlist_id nulled)', function () {
    $file = cachedMovieOn(Playlist::factory()->create());
    $file->update(['playlist_id' => null]);

    expect(app(CachedContentRetentionService::class)->evaluate()->all())->toBe([$file->id]);
});

it('evaluate() never returns active downloads', function () {
    $playlist = Playlist::factory()->create();
    $file = cachedMovieOn($playlist);
    $file->update(['status' => 'downloading']);
    Channel::whereKey($file->cacheable_id)->delete();

    expect(app(CachedContentRetentionService::class)->evaluate()->all())->toBeEmpty();
});

// --- retention modes ---

it('automaticPlaylistIdsQuery() agrees with Playlist::effectiveCacheRetentionMode() for every override/global combination', function () {
    $user = User::factory()->create();
    $playlists = collect(['automatic', 'never-expire', 'manual', '', null])
        ->map(fn (?string $mode) => Playlist::factory()->for($user)->create(['cache_retention_mode' => $mode]));

    $service = app(CachedContentRetentionService::class);
    $settings = app(GeneralSettings::class);

    foreach (['automatic', 'never-expire', 'manual', null] as $global) {
        $settings->cache_retention_mode = $global;
        $settings->save();

        $autoIds = $service->automaticPlaylistIdsQuery()->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($playlists as $playlist) {
            $effective = $playlist->fresh()->effectiveCacheRetentionMode();

            expect(in_array((int) $playlist->id, $autoIds, true))->toBe(
                $effective === 'automatic',
                "global={$global} override={$playlist->cache_retention_mode} effective={$effective}",
            );
        }
    }
});

it('evaluate() keeps stale rows for playlists set to never-expire or manual', function (string $mode) {
    $playlist = Playlist::factory()->create(['cache_retention_mode' => $mode]);
    $file = cachedMovieOn($playlist);
    Channel::whereKey($file->cacheable_id)->delete();

    expect(app(CachedContentRetentionService::class)->evaluate()->all())->toBeEmpty();
})->with(['never-expire', 'manual']);

it('evaluate() follows the global mode when the playlist has no override', function () {
    $settings = app(GeneralSettings::class);
    $settings->cache_retention_mode = 'never-expire';
    $settings->save();

    $playlist = Playlist::factory()->create(['cache_retention_mode' => null]);
    $file = cachedMovieOn($playlist);
    Channel::whereKey($file->cacheable_id)->delete();
    $service = app(CachedContentRetentionService::class);

    expect($service->evaluate()->all())->toBeEmpty();

    $settings->cache_retention_mode = 'automatic';
    $settings->save();

    expect($service->evaluate()->all())->toBe([$file->id]);
});

it('evaluate() lets a playlist override of automatic win over a never-expire global', function () {
    $settings = app(GeneralSettings::class);
    $settings->cache_retention_mode = 'never-expire';
    $settings->save();

    $file = cachedMovieOn(Playlist::factory()->create(['cache_retention_mode' => 'automatic']));
    Channel::whereKey($file->cacheable_id)->delete();

    expect(app(CachedContentRetentionService::class)->evaluate()->all())->toBe([$file->id]);
});

// --- deleteIds() ---

it('deleteIds removes the rows and their on-disk files', function () {
    Storage::fake(CachedContentFile::DISK);
    $file = cachedMovieOn(Playlist::factory()->create());
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'fake bytes');

    $count = app(CachedContentRetentionService::class)->deleteIds(collect([$file->id]));

    expect($count)->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull();
    Storage::disk(CachedContentFile::DISK)->assertMissing($file->file_path);
});

it('deleteIds only removes its own file, never another row file', function () {
    Storage::fake(CachedContentFile::DISK);
    $playlist = Playlist::factory()->create();
    $doomed = cachedMovieOn($playlist);
    $kept = cachedMovieOn($playlist);
    Storage::disk(CachedContentFile::DISK)->put($doomed->file_path, 'a');
    Storage::disk(CachedContentFile::DISK)->put($kept->file_path, 'b');

    app(CachedContentRetentionService::class)->deleteIds(collect([$doomed->id]));

    Storage::disk(CachedContentFile::DISK)->assertExists($kept->file_path);
});

it('deleteIds returns 0 when the ID list is empty', function () {
    expect(app(CachedContentRetentionService::class)->deleteIds(collect()))->toBe(0);
});

it('deleteIds skips rows whose status flipped back to Pending/Downloading after evaluate()', function () {
    $file = cachedMovieOn(Playlist::factory()->create());
    $file->update(['status' => 'downloading']);

    $count = app(CachedContentRetentionService::class)->deleteIds(collect([$file->id]));

    expect($count)->toBe(0)
        ->and(CachedContentFile::find($file->id))->not->toBeNull();
});
