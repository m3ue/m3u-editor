<?php

use App\Services\QueueIndicatorService;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

function makeQueueIndicatorService(array $workload, Collection $pendingJobs): QueueIndicatorService
{
    $workloadRepository = Mockery::mock(WorkloadRepository::class);
    $workloadRepository->shouldReceive('get')->andReturn($workload);

    $jobRepository = Mockery::mock(JobRepository::class);
    $jobRepository->shouldReceive('getPending')->andReturn($pendingJobs);
    $jobRepository->shouldReceive('countPending')->andReturn($pendingJobs->count());

    return new QueueIndicatorService($workloadRepository, $jobRepository);
}

it('returns zero counts for an empty queue', function () {
    $snapshot = makeQueueIndicatorService([], collect())->getSnapshot(5);

    expect($snapshot)
        ->running->toBe(0)
        ->queued->toBe(0)
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
            (object) ['id' => '2', 'name' => 'App\\Jobs\\SyncPlaylist', 'queue' => 'default', 'connection' => 'redis', 'status' => 'pending'],
            (object) ['id' => '3', 'name' => 'App\\Jobs\\ProbeChannelStreams', 'queue' => 'default', 'connection' => 'redis', 'status' => 'pending'],
        ])
    )->getSnapshot(10);

    expect($snapshot['running'])->toBe(1)
        ->and($snapshot['queued'])->toBe(3)
        ->and($snapshot['upcoming'])->toHaveCount(2)
        ->and($snapshot['upcoming'][0])->toMatchArray([
            'id' => '2',
            'name' => 'App\\Jobs\\SyncPlaylist',
            'queue' => 'default',
            'connection' => 'redis',
            'status' => 'pending',
        ]);
});

it('returns a safe degraded payload when Horizon cannot be read', function () {
    $workloadRepository = Mockery::mock(WorkloadRepository::class);
    $workloadRepository->shouldReceive('get')->andThrow(new RuntimeException('redis unavailable'));

    $jobRepository = Mockery::mock(JobRepository::class);

    $snapshot = (new QueueIndicatorService($workloadRepository, $jobRepository))->getSnapshot(10);

    expect($snapshot)
        ->running->toBe(0)
        ->queued->toBe(0)
        ->upcoming->toBeArray()->toHaveCount(0)
        ->degraded->toBeTrue()
        ->reason->toBe('horizon_unavailable')
        ->and($snapshot)->toHaveKey('as_of');
});
