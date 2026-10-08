<?php

/**
 * Issue #1529: sync logs split live, VOD and series content, and log added/removed series.
 */

use App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\RelationManagers\LogsRelationManager;
use App\Jobs\ProcessM3uImportComplete;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistSyncStatus;
use App\Models\PlaylistSyncStatusLog;
use App\Models\Series;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;

beforeEach(function () {
    $this->partialMock(SyncPipelineService::class, function ($mock) {
        $mock->shouldReceive('startRun')->andReturnNull();
        $mock->shouldReceive('expandPipelineAfterImport')->andReturnNull();
        $mock->shouldReceive('completePhase')->andReturnNull();
    });

    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($this->user)->create());
});

function seedSyncLogContent(Playlist $playlist, User $user): void
{
    foreach (['live', 'vod'] as $type) {
        $oldGroup = Group::factory()->for($playlist)->for($user)->create([
            'name' => "Old {$type} group",
            'type' => $type,
            'custom' => false,
            'import_batch_no' => 'previous-batch',
        ]);
        Channel::factory()->for($playlist)->for($user)->for($oldGroup)->create([
            'title' => "Old {$type} channel",
            'is_vod' => $type === 'vod',
            'is_custom' => false,
            'import_batch_no' => 'previous-batch',
        ]);

        $newGroup = Group::factory()->for($playlist)->for($user)->create([
            'name' => "New {$type} group",
            'type' => $type,
            'custom' => false,
            'new' => true,
            'import_batch_no' => 'current-batch',
        ]);
        Channel::factory()->for($playlist)->for($user)->for($newGroup)->create([
            'title' => "New {$type} channel",
            'is_vod' => $type === 'vod',
            'is_custom' => false,
            'new' => true,
            'import_batch_no' => 'current-batch',
        ]);
    }

    $staleCategory = Category::factory()->for($playlist)->for($user)->create(['import_batch_no' => 'previous-batch']);
    Series::factory()->for($playlist)->for($user)->create([
        'name' => 'Removed series',
        'category_id' => $staleCategory->id,
        'import_batch_no' => 'previous-batch',
    ]);

    $currentCategory = Category::factory()->for($playlist)->for($user)->create(['import_batch_no' => 'current-batch']);
    Series::factory()->for($playlist)->for($user)->create([
        'name' => 'Existing series',
        'category_id' => $currentCategory->id,
        'import_batch_no' => 'previous-batch',
    ]);
    Series::factory()->for($playlist)->for($user)->create([
        'name' => 'Added series',
        'category_id' => $currentCategory->id,
        'import_batch_no' => 'current-batch',
    ]);
}

function runSyncLogImportComplete(User $user, Playlist $playlist, bool $runningSeriesImport): void
{
    $settings = app(GeneralSettings::class);
    $settings->suppress_success_notifications = true;
    app()->instance(GeneralSettings::class, $settings);

    (new ProcessM3uImportComplete(
        userId: $user->id,
        playlistId: $playlist->id,
        batchNo: 'current-batch',
        start: Carbon::now()->subMinute(),
        runningLiveImport: true,
        runningVodImport: true,
        runningSeriesImport: $runningSeriesImport,
    ))->handle($settings);
}

it('tags live and vod entries and logs added and removed series', function () {
    seedSyncLogContent($this->playlist, $this->user);

    runSyncLogImportComplete($this->user, $this->playlist, runningSeriesImport: true);

    $sync = PlaylistSyncStatus::where('playlist_id', $this->playlist->id)->sole();
    $entries = $sync->logs()
        ->get()
        ->map(fn ($log) => "{$log->status} {$log->type} {$log->content_type}: {$log->name}")
        ->sort()
        ->values()
        ->all();

    expect($entries)->toBe([
        'added channel live: New live channel',
        'added channel vod: New vod channel',
        'added group live: New live group',
        'added group vod: New vod group',
        'added series series: Added series',
        'removed channel live: Old live channel',
        'removed channel vod: Old vod channel',
        'removed group live: Old live group',
        'removed group vod: Old vod group',
        'removed series series: Removed series',
    ])
        ->and($sync->sync_stats['added_series'])->toBe(1)
        ->and($sync->sync_stats['removed_series'])->toBe(1)
        ->and($sync->sync_stats['added_channels'])->toBe(2);

    $tabs = LogsRelationManager::setupTabs($sync->id, 'vod');
    expect($tabs['added_channels']->getBadge())->toEqual(1)
        ->and($tabs['added_series']->getBadge())->toEqual(0);
});

it('does not log series when series import did not run', function () {
    seedSyncLogContent($this->playlist, $this->user);

    runSyncLogImportComplete($this->user, $this->playlist, runningSeriesImport: false);

    $sync = PlaylistSyncStatus::where('playlist_id', $this->playlist->id)->sole();

    expect($sync->logs()->where('type', 'series')->count())->toBe(0)
        ->and($sync->sync_stats['removed_series'])->toBe(0);
});

it('does not invalidate on the series threshold when series import did not run', function () {
    config([
        'dev.invalidate_import' => true,
        'dev.invalidate_import_threshold' => 100,
        'dev.invalidate_import_group_threshold' => 100,
        'dev.invalidate_import_series_threshold' => 0,
    ]);
    seedSyncLogContent($this->playlist, $this->user);

    runSyncLogImportComplete($this->user, $this->playlist, runningSeriesImport: false);

    $sync = PlaylistSyncStatus::where('playlist_id', $this->playlist->id)->sole();
    expect($sync->sync_stats['status'])->toBe('success');
});

it('still invalidates on the series threshold when series import ran', function () {
    config([
        'dev.invalidate_import' => true,
        'dev.invalidate_import_threshold' => 100,
        'dev.invalidate_import_group_threshold' => 100,
        'dev.invalidate_import_series_threshold' => 0,
    ]);
    seedSyncLogContent($this->playlist, $this->user);

    runSyncLogImportComplete($this->user, $this->playlist, runningSeriesImport: true);

    $sync = PlaylistSyncStatus::where('playlist_id', $this->playlist->id)->sole();
    expect($sync->sync_stats['status'])->toBe('canceled')
        ->and($sync->removedSeries()->count())->toBe(1);
});

it('backfills the content type of existing log entries from their meta', function () {
    $migration = require database_path('migrations/2026_10_08_120000_add_content_type_to_playlist_sync_status_logs.php');
    $migration->down();

    $sync = PlaylistSyncStatus::create([
        'name' => $this->playlist->name,
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'sync_stats' => ['status' => 'success'],
    ]);
    $vodChannel = Channel::factory()->for($this->playlist)->for($this->user)->create(['title' => 'Movie', 'is_vod' => true]);
    $liveChannel = Channel::factory()->for($this->playlist)->for($this->user)->create(['title' => 'News', 'is_vod' => false]);
    $vodGroup = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'vod']);
    $liveGroup = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'live']);

    $now = now();
    PlaylistSyncStatusLog::insert(collect([
        ['vod channel', 'channel', $vodChannel],
        ['live channel', 'channel', $liveChannel],
        ['vod group', 'group', $vodGroup],
        ['live group', 'group', $liveGroup],
    ])->map(fn (array $row) => [
        'playlist_sync_status_id' => $sync->id,
        'name' => $row[0],
        'type' => $row[1],
        'status' => 'added',
        'meta' => $row[2],
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ])->all());

    $migration->up();

    expect(PlaylistSyncStatusLog::pluck('content_type', 'name')->all())->toEqual([
        'vod channel' => 'vod',
        'live channel' => 'live',
        'vod group' => 'vod',
        'live group' => 'live',
    ]);
});
