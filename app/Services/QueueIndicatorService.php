<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

class QueueIndicatorService
{
    public function __construct(
        private readonly WorkloadRepository $workloadRepository,
        private readonly JobRepository $jobRepository,
        private readonly BatchRepository $batchRepository,
    ) {}

    public function getSnapshot(int $limit = 10): array
    {
        $asOf = CarbonImmutable::now();
        $degradedReasons = [];

        try {
            $workload = $this->workloadRepository->get();
            $pending = $this->jobRepository->getPending();
            $jobs = $this->normalizeJobs($pending);

            $runningJobs = $jobs->filter(fn (array $job): bool => in_array($job['status'], ['reserved', 'running'], true));
            $upcomingJobs = $jobs->reject(fn (array $job): bool => in_array($job['status'], ['reserved', 'running'], true));

            $running = $runningJobs->count();
            $queued = $this->resolveQueuedCount($workload, $upcomingJobs);
        } catch (Throwable) {
            $running = 0;
            $queued = 0;
            $runningJobs = collect();
            $upcomingJobs = collect();
            $degradedReasons[] = 'horizon_unavailable';
        }

        try {
            $batches = $this->activeBatches($limit, $asOf);
        } catch (Throwable) {
            $batches = [];
            $degradedReasons[] = 'batches_unavailable';
        }

        return array_filter([
            'running' => $running,
            'queued' => $queued,
            'running_jobs' => $runningJobs->take($limit)->values()->all(),
            'upcoming' => $upcomingJobs->take($limit)->values()->all(),
            'batches' => $batches,
            'degraded' => $degradedReasons !== [],
            'reason' => $degradedReasons !== [] ? implode(',', array_unique($degradedReasons)) : null,
            'as_of' => $asOf->toIso8601String(),
        ], fn ($v) => $v !== null);
    }

    private function normalizeJobs(Collection $jobs): Collection
    {
        return $jobs->map(function (object $job): array {
            $payload = [];

            try {
                $raw = is_string($job->payload ?? null) ? json_decode($job->payload, true) : [];
                $payload = $raw['data'] ?? [];
            } catch (Throwable) {
                //
            }

            $batchId = $payload['batchId'] ?? null;
            $currentChunk = isset($payload['currentChunk']) ? (int) $payload['currentChunk'] : null;
            $totalChunks = isset($payload['totalChunks']) ? (int) $payload['totalChunks'] : null;

            return [
                'id' => $job->id ?? null,
                'name' => $job->name ?? null,
                'human_name' => Str::headline(class_basename($job->name ?? '')),
                'queue' => $job->queue ?? null,
                'status' => $job->status ?? 'pending',
                'batch_id' => $batchId,
                'chunk' => ($currentChunk !== null && $totalChunks !== null)
                    ? ['current' => $currentChunk, 'total' => $totalChunks]
                    : null,
            ];
        });
    }

    private function resolveQueuedCount(array $workload, Collection $upcoming): int
    {
        $fromWorkload = collect($workload)->sum(fn (array $q): int => (int) ($q['length'] ?? 0));

        return $fromWorkload > 0 ? $fromWorkload : $upcoming->count();
    }

    private function activeBatches(int $limit, CarbonImmutable $asOf): array
    {
        $batches = $this->batchRepository->get($limit, null);

        return collect($batches)
            ->filter(fn (Batch $batch): bool => ! $batch->finished() && ! $batch->cancelled())
            ->map(fn (Batch $batch): array => $this->normalizeBatch($batch, $asOf))
            ->values()
            ->all();
    }

    private function normalizeBatch(Batch $batch, CarbonImmutable $asOf): array
    {
        $processed = $batch->processedJobs();
        $progress = $batch->progress();
        $etaLabel = null;

        if ($processed > 0 && $batch->pendingJobs > 0) {
            $elapsedSeconds = $batch->createdAt->diffInSeconds($asOf);
            $rate = $elapsedSeconds > 0 ? $processed / $elapsedSeconds : 0;

            if ($rate > 0) {
                $remainingSeconds = (int) ceil($batch->pendingJobs / $rate);
                $etaLabel = $this->formatDuration($remainingSeconds);
            }
        }

        return [
            'id' => $batch->id,
            'name' => $batch->name,
            'total' => $batch->totalJobs,
            'pending' => $batch->pendingJobs,
            'processed' => $processed,
            'failed' => $batch->failedJobs,
            'progress' => $progress,
            'status' => $batch->failedJobs > 0 ? 'failing' : 'running',
            'created_at' => $batch->createdAt->toIso8601String(),
            'eta_label' => $etaLabel,
        ];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        if ($minutes < 60) {
            return $remaining > 0 ? "{$minutes}m {$remaining}s" : "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return $mins > 0 ? "{$hours}h {$mins}m" : "{$hours}h";
    }
}
