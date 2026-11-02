<?php

use App\Enums\Status;
use App\Enums\SyncRunStatus;
use App\Filament\Resources\EpgMaps\Pages\ListEpgMaps;
use App\Filament\Resources\Epgs\Pages\ListEpgs;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Playlists\Pages\ListPlaylists;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Jobs\Middleware\SkipIfSyncReset;
use App\Jobs\ProcessEpgImport;
use App\Jobs\ProcessM3uImport;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\SyncPipelineService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Pipeline;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\RecordsSyncStepJob;

/**
 * Whether $job would run, or be skipped by its own middleware (#1806).
 */
function syncResetGuardLetsJobRun(object $job): bool
{
    return Pipeline::send($job)
        ->through($job->middleware())
        ->then(fn (): bool => true) === true;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $this->epg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create());
});

it('drops the rest of a sync chain once the playlist is reset mid-run', function () {
    $guard = new SkipIfSyncReset(Playlist::class, $this->playlist->id, microtime(true));

    // The default queue connection is Redis even in tests, so run the chain inline
    Bus::chain($guard->guard([
        new RecordsSyncStepJob('first', resetPlaylistId: $this->playlist->id),
        new RecordsSyncStepJob('second'),
        new RecordsSyncStepJob('third'),
    ]))->onConnection('sync')->dispatch();

    expect(cache('sync-steps'))->toBe(['first']);
});

it('runs a sync queued after the reset, and runs as before with no reset', function (bool $resetFirst) {
    if ($resetFirst) {
        SkipIfSyncReset::recordReset($this->playlist);
    }

    $guard = new SkipIfSyncReset(Playlist::class, $this->playlist->id, microtime(true));
    Bus::chain($guard->guard([new RecordsSyncStepJob('first'), new RecordsSyncStepJob('second')]))->onConnection('sync')->dispatch();

    expect(cache('sync-steps'))->toBe(['first', 'second']);
})->with([
    'reset before the sync was queued' => true,
    'no reset recorded' => false,
]);

it('only skips syncs of the model that was reset', function () {
    $otherPlaylist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $queued = new ProcessM3uImport($this->playlist, force: true);

    SkipIfSyncReset::recordReset($otherPlaylist);
    SkipIfSyncReset::recordReset($this->epg);

    expect(syncResetGuardLetsJobRun($queued))->toBeTrue();
});

it('cancels the running sync when a playlist is reset', function (string $action) {
    Queue::fake();
    $this->actingAs($this->user);
    $this->playlist->update([
        'status' => Status::Processing,
        'processing' => ['live_processing' => true, 'vod_processing' => false, 'series_processing' => false],
    ]);
    $run = SyncRun::factory()->for($this->playlist)->create([
        'status' => SyncRunStatus::Running->value,
        'started_at' => now()->subHour(),
        'finished_at' => null,
    ]);
    $queuedImport = new ProcessM3uImport($this->playlist, force: true, syncRunId: $run->id);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->callAction($action);

    $run->refresh();
    expect($run->status)->toBe(SyncRunStatus::Cancelled->value)
        ->and($run->finished_at)->not->toBeNull()
        ->and(syncResetGuardLetsJobRun($queuedImport))->toBeFalse();

    // The next sync gets its own run instead of attaching to the cancelled one, and runs
    $nextRun = app(SyncPipelineService::class)->startImport($this->playlist->fresh());
    expect($nextRun->id)->not->toBe($run->id)
        ->and(syncResetGuardLetsJobRun(new ProcessM3uImport($this->playlist->fresh(), force: true, syncRunId: $nextRun->id)))->toBeTrue();
})->with(['reset', 'reset_processing']);

it('cancels the running sync of every playlist reset in bulk', function () {
    Queue::fake();
    $this->actingAs($this->user);
    // Processing playlists can't be selected for bulk actions; the post-import phases still run on a Running SyncRun
    $this->playlist->update(['status' => Status::Completed]);
    $run = SyncRun::factory()->for($this->playlist)->create([
        'status' => SyncRunStatus::Running->value,
        'started_at' => now()->subHour(),
        'finished_at' => null,
    ]);

    Livewire::test(ListPlaylists::class)
        ->selectTableRecords([$this->playlist])
        ->callAction(TestAction::make('reset')->table()->bulk());

    expect($run->fresh()->status)->toBe(SyncRunStatus::Cancelled->value);
});

it('skips an EPG import queued before the EPG was reset', function () {
    Queue::fake();
    $this->actingAs($this->user);
    $this->epg->update(['status' => Status::Processing, 'processing' => true]);
    $queuedImport = new ProcessEpgImport($this->epg, force: true);

    Livewire::test(ListEpgs::class)
        ->callAction(TestAction::make('reset')->table($this->epg));

    expect(syncResetGuardLetsJobRun($queuedImport))->toBeFalse()
        ->and(syncResetGuardLetsJobRun(new ProcessEpgImport($this->epg->fresh(), force: true)))->toBeTrue();
});

it('skips the previous run of an EPG map once it is restarted', function () {
    Queue::fake();
    Bus::fake([MapPlaylistChannelsToEpg::class]);
    $this->actingAs($this->user);
    $map = EpgMap::factory()->create([
        'epg_id' => $this->epg->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'status' => Status::Processing,
        'processing' => true,
    ]);
    $previousRun = new MapPlaylistChannelsToEpg(epg: $this->epg->id, playlist: $this->playlist->id, epgMapId: $map->id);

    Livewire::test(ListEpgMaps::class)
        ->callAction(TestAction::make('restart')->table($map));

    expect(syncResetGuardLetsJobRun($previousRun))->toBeFalse();
    Bus::assertDispatched(MapPlaylistChannelsToEpg::class, fn (MapPlaylistChannelsToEpg $job) => syncResetGuardLetsJobRun($job));
});
