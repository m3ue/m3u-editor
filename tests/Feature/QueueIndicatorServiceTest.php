<?php

use App\Services\QueueIndicatorService;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

function makeQueueIndicatorService(array $workload, Collection $pendingJobs, array $batches = []): QueueIndicatorService
{
    $workloadRepository = Mockery::mock(WorkloadRepository::class);
    $workloadRepository->shouldReceive('get')->andReturn($workload);

    $jobRepository = Mockery::mock(JobRepository::class);
    $jobRepository->shouldReceive('getPending')->andReturn($pendingJobs);
    $jobRepository->shouldReceive('countPending')->andReturn($pendingJobs->count());

    $batchRepository = Mockery::mock(BatchRepository::class);
    $batchRepository->shouldReceive('get')->andReturn($batches);

    return new QueueIndicatorService($workloadRepository, $jobRepository, $batchRepository);
}

function makeQueueIndicatorBatch(array $overrides = []): Batch
{
    $batch = Mockery::mock(Batch::class);
    $batch->id = $overrides['id'] ?? 'batch-123';
    $batch->name = $overrides['name'] ?? 'Probe Channels';
    $batch->totalJobs = $overrides['totalJobs'] ?? 10;
    $batch->pendingJobs = $overrides['pendingJobs'] ?? 4;
    $batch->failedJobs = $overrides['failedJobs'] ?? 1;
    $batch->createdAt = $overrides['createdAt'] ?? Carbon::parse('2026-01-01 12:00:00');

    $batch->shouldReceive('finished')->andReturn($overrides['finished'] ?? false);
    $batch->shouldReceive('cancelled')->andReturn($overrides['cancelled'] ?? false);
    $batch->shouldReceive('processedJobs')->andReturn($overrides['processedJobs'] ?? 6);
    $batch->shouldReceive('progress')->andReturn($overrides['progress'] ?? 60);

    return $batch;
}

it('returns zero counts for an empty queue', function () {
    $snapshot = makeQueueIndicatorService([], collect())->getSnapshot(5);

    expect($snapshot)
        ->running->toBe(0)
        ->queued->toBe(0)
        ->running_jobs->toBeArray()->toHaveCount(0)
        ->batches->toBeArray()->toHaveCount(0)
        ->upcoming->toBeArray()->toHaveCount(0)
        ->degraded->toBeFalse()
        ->and($snapshot)->toHaveKey('as_of');
});

it('extracts running and queued counts from Horizon data', function () {
    $snapshot = makeQueueIndicatorService(
        [
            ['name' => 'default', 'length' => 3, 'wait' => 20, 'processes' => 2, 'split_queues' => null],
        ],
        collect([
            (object) ['id' => '1', 'name' => 'App\\Jobs\\ImportPlaylist', 'queue' => 'default', 'connection' => 'redis', 'status' => 'reserved'],
            (object) ['id' => '2', 'name' => 'App\\Jobs\\SyncPlaylist', 'queue' => 'default', 'connection' => 'redis', 'status' => 'pending', 'payload' => json_encode(['data' => ['batchId' => 'batch-123', 'currentChunk' => 4, 'totalChunks' => 20]])],
            (object) ['id' => '3', 'name' => 'App\\Jobs\\ProbeChannelStreams', 'queue' => 'default', 'connection' => 'redis', 'status' => 'pending'],
        ])
    )->getSnapshot(10);

    expect($snapshot['running'])->toBe(1)
        ->and($snapshot['queued'])->toBe(3)
        ->and($snapshot['running_jobs'])->toHaveCount(1)
        ->and($snapshot['running_jobs'][0])->toMatchArray([
            'id' => '1',
            'name' => 'App\\Jobs\\ImportPlaylist',
            'human_name' => 'Import Playlist',
            'status' => 'reserved',
            'status_label' => 'Running',
        ])
        ->and($snapshot['upcoming'])->toHaveCount(2)
        ->and($snapshot['upcoming'][0])->toMatchArray([
            'id' => '2',
            'name' => 'App\\Jobs\\SyncPlaylist',
            'queue' => 'default',
            'connection' => 'redis',
            'status' => 'pending',
            'batch_id' => 'batch-123',
            'chunk_current' => 5,
            'chunk_total' => 20,
            'chunk_label' => 'Chunk 5 of 20',
        ]);
});

it('counts all running jobs even when only a limited list is shown', function () {
    $snapshot = makeQueueIndicatorService(
        [],
        collect([
            (object) ['id' => '1', 'name' => 'App\\Jobs\\ImportPlaylist', 'status' => 'reserved'],
            (object) ['id' => '2', 'name' => 'App\\Jobs\\SyncPlaylist', 'status' => 'reserved'],
            (object) ['id' => '3', 'name' => 'App\\Jobs\\ProbeChannelStreams', 'status' => 'reserved'],
        ])
    )->getSnapshot(2);

    expect($snapshot['running'])->toBe(3)
        ->and($snapshot['running_jobs'])->toHaveCount(2);
});

it('adds active batch progress to the snapshot', function () {
    $snapshot = makeQueueIndicatorService(
        [],
        collect(),
        [makeQueueIndicatorBatch()]
    )->getSnapshot(10);

    expect($snapshot['batches'])->toHaveCount(1)
        ->and($snapshot['batches'][0])->toMatchArray([
            'id' => 'batch-123',
            'name' => 'Probe Channels',
            'total' => 10,
            'pending' => 4,
            'processed' => 6,
            'failed' => 1,
            'progress' => 60,
            'status' => 'running',
        ]);
});

it('returns a safe degraded payload when Horizon cannot be read', function () {
    $workloadRepository = Mockery::mock(WorkloadRepository::class);
    $workloadRepository->shouldReceive('get')->andThrow(new RuntimeException('redis unavailable'));

    $jobRepository = Mockery::mock(JobRepository::class);

    $batchRepository = Mockery::mock(BatchRepository::class);
    $batchRepository->shouldReceive('get')->andReturn([]);

    $snapshot = (new QueueIndicatorService($workloadRepository, $jobRepository, $batchRepository))->getSnapshot(10);

    expect($snapshot)
        ->running->toBe(0)
        ->queued->toBe(0)
        ->batches->toBeArray()->toHaveCount(0)
        ->upcoming->toBeArray()->toHaveCount(0)
        ->degraded->toBeTrue()
        ->reason->toBe('horizon_unavailable')
        ->and($snapshot)->toHaveKey('as_of');
});
