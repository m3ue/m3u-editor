<?php

use App\Jobs\ProcessM3uImport;
use App\Jobs\ProcessM3uImportComplete;
use App\Jobs\ProcessM3uImportSeriesChunk;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('calculates series progress from the filtered categories', function () {
    $user = User::factory()->create();
    $playlist = Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create([
        'xtream' => true,
        'enable_channels' => false,
        'enable_vod_channels' => false,
        'enable_series' => true,
        'import_prefs' => [
            'preprocess' => true,
            'import_via_category' => true,
            'selected_categories' => ['Selected Series'],
            'included_vod_group_prefixes' => ['unused'],
        ],
        'xtream_config' => [
            'url' => 'http://xtream.test',
            'username' => 'user',
            'password' => 'pass',
            'import_options' => ['series'],
        ],
    ]));

    Http::preventStrayRequests();
    Http::fake([
        '*action=get_series_categories*' => Http::response([
            ['category_id' => 1, 'category_name' => 'Excluded Before'],
            ['category_id' => 2, 'category_name' => 'Selected Series'],
            ['category_id' => 3, 'category_name' => 'Excluded After'],
        ]),
        '*player_api.php*' => Http::response([
            'user_info' => ['auth' => 1],
            'server_info' => [],
        ]),
    ]);
    Bus::fake();

    (new ProcessM3uImport($playlist, force: true))->handle();

    expect($playlist->fresh()->errors)->toBeNull();
    Bus::assertChained([
        ProcessM3uImportSeriesChunk::class,
        fn (ProcessM3uImportComplete $job): bool => $job->runningSeriesImport,
    ]);
    Bus::assertDispatched(ProcessM3uImportSeriesChunk::class, fn (ProcessM3uImportSeriesChunk $job): bool => (int) $job->payload['categoryId'] === 2
        && $job->batchCount === 1
        && $job->index === 0);
});

it('marks series progress complete when the series import finishes', function () {
    config(['dev.disable_sync_logs' => true]);

    $settings = app(GeneralSettings::class);
    $settings->suppress_success_notifications = true;
    app()->instance(GeneralSettings::class, $settings);

    $this->partialMock(SyncPipelineService::class, function ($mock) {
        $mock->shouldReceive('startRun')->andReturnNull();
        $mock->shouldReceive('expandPipelineAfterImport')->andReturnNull();
        $mock->shouldReceive('completePhase')->andReturnNull();
    });

    $user = User::factory()->create();
    $playlist = Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create([
        'series_progress' => 59,
    ]));

    (new ProcessM3uImportComplete(
        userId: $user->id,
        playlistId: $playlist->id,
        batchNo: 'series-progress-batch',
        start: Carbon::now()->subMinute(),
        runningLiveImport: false,
        runningVodImport: false,
        runningSeriesImport: true,
    ))->handle($settings);

    expect($playlist->fresh()->series_progress)->toEqual(100);
});
