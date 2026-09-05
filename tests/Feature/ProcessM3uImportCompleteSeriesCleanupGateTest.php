<?php

/**
 * Regression test for issue #1482: ProcessM3uImportComplete::seriesCleanup()
 * deletes every category/series whose import_batch_no doesn't match the current
 * run. If series import didn't actually run this sync (disabled, or the
 * provider's get_series_categories resolved to zero categories), running that
 * cleanup would wipe the user's whole series library on a no-op or a provider
 * blip. It must only run when series import genuinely ran (runningSeriesImport).
 */

use App\Jobs\ProcessM3uImportComplete;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;

function seedStaleSeriesFor(Playlist $playlist, User $user): Series
{
    $category = Category::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'import_batch_no' => 'previous-batch',
    ]);

    return Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'category_id' => $category->id,
        'import_batch_no' => 'previous-batch',
    ]);
}

function runSeriesCleanupImportComplete(User $user, Playlist $playlist, bool $runningSeriesImport): void
{
    $settings = app(GeneralSettings::class);
    $settings->suppress_success_notifications = true;
    app()->instance(GeneralSettings::class, $settings);

    test()->partialMock(SyncPipelineService::class, function ($mock) {
        $mock->shouldReceive('startRun')->andReturnNull();
        $mock->shouldReceive('expandPipelineAfterImport')->andReturnNull();
        $mock->shouldReceive('completePhase')->andReturnNull();
    });

    (new ProcessM3uImportComplete(
        userId: $user->id,
        playlistId: $playlist->id,
        batchNo: 'current-batch',
        start: Carbon::now()->subMinute(),
        runningLiveImport: false,
        runningVodImport: false,
        runningSeriesImport: $runningSeriesImport,
    ))->handle($settings);
}

it('does not delete stale series when series import did not run this sync', function () {
    config(['dev.disable_sync_logs' => true]);

    $user = User::factory()->create();
    $playlist = Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create());
    $series = seedStaleSeriesFor($playlist, $user);

    runSeriesCleanupImportComplete($user, $playlist, runningSeriesImport: false);

    expect(Series::find($series->id))->not->toBeNull();
});

it('still cleans up stale series when series import did run this sync', function () {
    config(['dev.disable_sync_logs' => true]);

    $user = User::factory()->create();
    $playlist = Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create());
    $series = seedStaleSeriesFor($playlist, $user);

    runSeriesCleanupImportComplete($user, $playlist, runningSeriesImport: true);

    expect(Series::find($series->id))->toBeNull();
});
