<?php

/**
 * Regression tests for ProcessVodChannels status-overwrite bug.
 *
 * The import pipeline sets playlist status = Completed before dispatching the
 * VOD job chain.  ProcessVodChannels must NOT overwrite that back to Processing,
 * otherwise SyncListener sees Processing at the moment SyncCompleted fires and
 * falls back to buildPostProcessOnly() — producing an incomplete post-sync plan.
 */

use App\Enums\Status;
use App\Jobs\ProcessVodChannels;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\XtreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
    ]);
});

it('does not overwrite playlist status to Processing when VOD channels exist', function () {
    // Create a VOD channel that needs metadata fetching (last_metadata_fetch=null)
    Channel::factory()->for($this->playlist)->for($this->user)->create([
        'is_vod' => true,
        'enabled' => true,
        'source_id' => '12345',
        'last_metadata_fetch' => null,
    ]);

    $job = new ProcessVodChannels(playlist: $this->playlist);
    $job->handle(app(XtreamService::class));

    expect($this->playlist->fresh()->status)
        ->toBe(Status::Completed, 'ProcessVodChannels must not overwrite status=Completed with Processing');
});

it('leaves playlist status as Completed when there are no VOD channels to process', function () {
    // No VOD channels — the zero-channels path explicitly sets Completed, which is a no-op
    $job = new ProcessVodChannels(playlist: $this->playlist);
    $job->handle(app(XtreamService::class));

    expect($this->playlist->fresh()->status)->toBe(Status::Completed);
});
