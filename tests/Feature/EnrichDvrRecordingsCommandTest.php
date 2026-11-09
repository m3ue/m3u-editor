<?php

/**
 * Issue #1737: `dvr:enrich --all` promises to re-enrich completed recordings even when
 * already enriched, but previously sent any recording with nonempty metadata straight
 * to VOD integration regardless of --all, skipping the enrichment pipeline entirely.
 */

use App\Enums\DvrRecordingStatus;
use App\Jobs\EnrichDvrMetadata;
use App\Jobs\IntegrateDvrRecordingToVod;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->setting = DvrSetting::factory()->enabled()->for($this->user)->create([
        'enable_metadata_enrichment' => true,
    ]);
});

function makeEnrichedRecording(): DvrRecording
{
    return DvrRecording::factory()
        ->for(test()->setting, 'dvrSetting')
        ->for(test()->user)
        ->create([
            'status' => DvrRecordingStatus::Completed,
            'file_path' => 'recordings/already-enriched.mp4',
            'metadata' => ['title' => 'Existing Metadata'],
        ]);
}

it('re-enriches an already-enriched recording with --all', function () {
    $recording = makeEnrichedRecording();

    $this->artisan('dvr:enrich', ['--all' => true])->assertSuccessful();

    Queue::assertPushed(EnrichDvrMetadata::class, fn ($job) => $job->recordingId === $recording->id);
    Queue::assertNotPushed(IntegrateDvrRecordingToVod::class);
});

it('skips enrichment for an already-enriched recording targeted by --id', function () {
    $recording = makeEnrichedRecording();

    $this->artisan('dvr:enrich', ['--id' => $recording->id])->assertSuccessful();

    Queue::assertNotPushed(EnrichDvrMetadata::class);
    Queue::assertPushed(IntegrateDvrRecordingToVod::class, fn ($job) => $job->recordingId === $recording->id);
});

it('still goes straight to integration with --integrate-only even when --all is also passed', function () {
    $recording = makeEnrichedRecording();

    $this->artisan('dvr:enrich', ['--all' => true, '--integrate-only' => true])->assertSuccessful();

    Queue::assertNotPushed(EnrichDvrMetadata::class);
    Queue::assertPushed(IntegrateDvrRecordingToVod::class, fn ($job) => $job->recordingId === $recording->id);
});

it('re-enriches a recording missing metadata by default without --all', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'status' => DvrRecordingStatus::Completed,
            'file_path' => 'recordings/missing-metadata.mp4',
            'metadata' => null,
        ]);

    $this->artisan('dvr:enrich')->assertSuccessful();

    Queue::assertPushed(EnrichDvrMetadata::class, fn ($job) => $job->recordingId === $recording->id);
});
