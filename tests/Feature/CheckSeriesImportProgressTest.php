<?php

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\CheckSeriesImportProgress;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function mockCheckSeriesSettings(bool $tmdbAutoLookupOnImport = false): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = $tmdbAutoLookupOnImport;

    app()->instance(GeneralSettings::class, $mock);
}

beforeEach(function () {
    Bus::fake();
    mockCheckSeriesSettings();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing,
    ]);
});

it('marks the playlist Completed BEFORE dispatching SyncCompleted so the orchestrator builds the full post-sync plan', function () {
    // Capture the playlist status at the exact moment SyncCompleted fires.
    $statusAtDispatch = null;
    $playlistId = $this->playlist->id;
    Event::listen(SyncCompleted::class, function (SyncCompleted $event) use (&$statusAtDispatch, $playlistId) {
        if ($event->model->id === $playlistId) {
            $statusAtDispatch = Playlist::find($playlistId)->status;
        }
    });

    $job = new CheckSeriesImportProgress(
        currentOffset: 5,
        totalSeries: 5,
        notify: false,
        playlist_id: $this->playlist->id,
        user_id: $this->user->id,
        sync_stream_files: true,
    );

    $job->handle(app(GeneralSettings::class));

    expect($statusAtDispatch)
        ->not->toBeNull('SyncCompleted should have been dispatched')
        ->toBe(Status::Completed);

    expect($this->playlist->fresh()->status)->toBe(Status::Completed);
});
