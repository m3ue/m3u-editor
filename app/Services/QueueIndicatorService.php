<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

class QueueIndicatorService
{
    public function __construct(
        private readonly WorkloadRepository $workloadRepository,
        private readonly JobRepository $jobRepository,
    ) {}

    /**
     * @return array{running: int, queued: int, upcoming: list<array{id: string|null, name: string|null, queue: string|null, connection: string|null, status: string|null}>, degraded: bool, as_of: string, reason?: string}
     */
    public function getSnapshot(int $upcomingLimit = 10): array
    {
        try {
            $workload = $this->workloadRepository->get();
            $pendingJobs = $this->jobRepository->getPending();

            $normalizedJobs = $this->normalizeJobs($pendingJobs);
            $running = $normalizedJobs
                ->filter(fn (array $job): bool => in_array($job['status'], ['reserved', 'running'], true))
                ->count();

            $upcoming = $normalizedJobs
                ->reject(fn (array $job): bool => in_array($job['status'], ['reserved', 'running'], true))
                ->take($upcomingLimit)
                ->values()
                ->all();

            return [
                'running' => $running,
                'queued' => $this->queuedCount($workload, $normalizedJobs),
                'upcoming' => $upcoming,
                'degraded' => false,
                'as_of' => CarbonImmutable::now()->toIso8601String(),
            ];
        } catch (Throwable) {
            return [
                'running' => 0,
                'queued' => 0,
                'upcoming' => [],
                'degraded' => true,
                'reason' => 'horizon_unavailable',
                'as_of' => CarbonImmutable::now()->toIso8601String(),
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $workload
     * @param  Collection<int, array{id: string|null, name: string|null, queue: string|null, connection: string|null, status: string|null}>  $jobs
     */
    private function queuedCount(array $workload, Collection $jobs): int
    {
        $workloadLength = collect($workload)->sum(fn (array $queue): int => (int) ($queue['length'] ?? 0));

        if ($workloadLength > 0) {
            return $workloadLength;
        }

        return $jobs->count();
    }

    /**
     * @param  Collection<int, object|array<string, mixed>>  $jobs
     * @return Collection<int, array{id: string|null, name: string|null, queue: string|null, connection: string|null, status: string|null}>
     */
    private function normalizeJobs(Collection $jobs): Collection
    {
        return $jobs
            ->map(function (object|array $job): array {
                return [
                    'id' => $this->jobValue($job, 'id'),
                    'name' => $this->jobValue($job, 'name'),
                    'queue' => $this->jobValue($job, 'queue'),
                    'connection' => $this->jobValue($job, 'connection'),
                    'status' => $this->jobValue($job, 'status'),
                ];
            })
            ->values();
    }

    private function jobValue(object|array $job, string $key): ?string
    {
        $value = is_array($job) ? ($job[$key] ?? null) : ($job->{$key} ?? null);

        return $value === null ? null : (string) $value;
    }
}
