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

    public function getSnapshot(int $upcomingLimit = 10): array
    {
        $asOf = CarbonImmutable::now();
        $degradedReasons = [];

        try {
            $workload = $this->workloadRepository->get();
            $pendingJobs = $this->jobRepository->getPending();
            $normalizedJobs = $this->normalizeJobs($pendingJobs, $asOf);

            $runningJobCollection = $normalizedJobs
                ->filter(fn (array $job): bool => in_array($job['status'], ['reserved', 'running'], true));

            $running = $runningJobCollection->count();
            $runningJobs = $runningJobCollection
                ->take($upcomingLimit)
                ->values()
                ->all();

            $upcoming = $normalizedJobs
                ->reject(fn (array $job): bool => in_array($job['status'], ['reserved', 'running'], true))
                ->take($upcomingLimit)
                ->values()
                ->all();

            $queued = $this->queuedCount($workload, $normalizedJobs);
        } catch (Throwable) {
            $running = 0;
            $queued = 0;
            $runningJobs = [];
            $upcoming = [];
            $degradedReasons[] = 'horizon_unavailable';
        }

        try {
            $batches = $this->activeBatches($upcomingLimit, $asOf);
        } catch (Throwable) {
            $batches = [];
            $degradedReasons[] = 'batches_unavailable';
        }

        return array_filter([
            'running' => $running,
            'queued' => $queued,
            'running_jobs' => $runningJobs,
            'batches' => $batches,
            'upcoming' => $upcoming,
            'degraded' => $degradedReasons !== [],
            'reason' => $degradedReasons === [] ? null : implode(',', array_unique($degradedReasons)),
            'as_of' => $asOf->toIso8601String(),
        ], fn ($value) => $value !== null);
    }

    private function queuedCount(array $workload, Collection $jobs): int
    {
        $workloadLength = collect($workload)->sum(fn (array $queue): int => (int) ($queue['length'] ?? 0));

        if ($workloadLength > 0) {
            return $workloadLength;
        }

        return $jobs->reject(fn (array $job): bool => in_array($job['status'], ['reserved', 'running'], true))->count();
    }

    private function activeBatches(int $limit, CarbonImmutable $asOf): array
    {
        return collect($this->batchRepository->get(max($limit, 10), null))
            ->filter(fn (Batch $batch): bool => ! $batch->finished() || $batch->pendingJobs > 0 || $batch->failedJobs > 0)
            ->take($limit)
            ->map(function (Batch $batch) use ($asOf): array {
                $processed = $batch->processedJobs();
                $createdAt = $batch->createdAt ? CarbonImmutable::instance($batch->createdAt) : null;

                return [
                    'id' => $batch->id,
                    'name' => $this->humanizeName($batch->name ?: __('Batch :id', ['id' => $batch->id])),
                    'total' => $batch->totalJobs,
                    'pending' => $batch->pendingJobs,
                    'processed' => $processed,
                    'failed' => $batch->failedJobs,
                    'progress' => $batch->progress(),
                    'status' => $this->batchStatus($batch),
                    'status_label' => $this->batchStatusLabel($batch),
                    'created_at' => $createdAt?->toIso8601String(),
                    'duration_label' => $createdAt ? $this->durationLabel($createdAt->diffInSeconds($asOf, true)) : null,
                    'eta_label' => $this->estimateBatchEta($batch, $createdAt, $asOf),
                ];
            })
            ->values()
            ->all();
    }

    private function batchStatus(Batch $batch): string
    {
        if ($batch->cancelled()) {
            return 'cancelled';
        }

        if ($batch->finished()) {
            return $batch->failedJobs > 0 ? 'finished_with_failures' : 'finished';
        }

        if ($batch->processedJobs() > 0) {
            return 'running';
        }

        return 'pending';
    }

    private function batchStatusLabel(Batch $batch): string
    {
        return match ($this->batchStatus($batch)) {
            'cancelled' => __('Cancelled'),
            'finished_with_failures' => __('Finished with failures'),
            'finished' => __('Finished'),
            'running' => __('Running'),
            default => __('Waiting to start'),
        };
    }

    private function estimateBatchEta(Batch $batch, ?CarbonImmutable $createdAt, CarbonImmutable $asOf): ?string
    {
        $processed = $batch->processedJobs();

        if ($createdAt === null || $processed <= 0 || $batch->pendingJobs <= 0) {
            return null;
        }

        $elapsedSeconds = max(1, $createdAt->diffInSeconds($asOf, true));
        $secondsPerJob = $elapsedSeconds / $processed;
        $remainingSeconds = (int) round($secondsPerJob * $batch->pendingJobs);

        return $this->durationLabel($remainingSeconds);
    }

    private function normalizeJobs(Collection $jobs, CarbonImmutable $asOf): Collection
    {
        return $jobs
            ->map(function (object|array $job) use ($asOf): array {
                $payload = $this->jobPayload($job);
                $payloadText = $this->jobPayloadText($job);
                $rawName = $this->jobValue($job, 'name') ?? $this->payloadDisplayName($payload);
                $timestamp = $this->jobTimestamp($job, $payload);
                $status = $this->jobValue($job, 'status');
                $chunk = $this->extractChunkMeta($payload, $payloadText);

                return [
                    'id' => $this->jobValue($job, 'id') ?? $this->payloadValue($payload, 'uuid'),
                    'name' => $rawName,
                    'human_name' => $this->humanizeName($rawName ?? __('Unknown job')),
                    'queue' => $this->jobValue($job, 'queue'),
                    'connection' => $this->jobValue($job, 'connection'),
                    'status' => $status,
                    'status_label' => $this->jobStatusLabel($status),
                    'batch_id' => $this->payloadBatchId($payload),
                    'chunk_current' => $chunk['current'],
                    'chunk_total' => $chunk['total'],
                    'chunk_label' => $chunk['label'],
                    'age_seconds' => $timestamp ? $timestamp->diffInSeconds($asOf, true) : null,
                    'age_label' => $timestamp ? $this->durationLabel($timestamp->diffInSeconds($asOf, true)) : null,
                ];
            })
            ->values();
    }

    private function jobValue(object|array $job, string $key): ?string
    {
        $value = is_array($job) ? ($job[$key] ?? null) : ($job->{$key} ?? null);

        return $value === null ? null : (string) $value;
    }

    private function jobPayload(object|array $job): array
    {
        $payload = is_array($job) ? ($job['payload'] ?? null) : ($job->payload ?? null);

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($payload) ? $payload : [];
    }

    private function jobPayloadText(object|array $job): string
    {
        $payload = is_array($job) ? ($job['payload'] ?? null) : ($job->payload ?? null);

        if (is_string($payload)) {
            return $payload;
        }

        if (is_array($payload)) {
            return json_encode($payload) ?: '';
        }

        return '';
    }

    private function jobTimestamp(object|array $job, array $payload): ?CarbonImmutable
    {
        foreach (['reserved_at', 'started_at', 'failed_at', 'completed_at', 'pushedAt', 'timestamp', 'created_at'] as $key) {
            $value = is_array($job) ? ($job[$key] ?? null) : ($job->{$key} ?? null);
            $value ??= $payload[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value)) {
                $timestamp = (int) $value;
                $timestamp = $timestamp > 9999999999 ? (int) floor($timestamp / 1000) : $timestamp;

                return CarbonImmutable::createFromTimestamp($timestamp);
            }

            try {
                return CarbonImmutable::parse((string) $value);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function payloadValue(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function payloadBatchId(array $payload): ?string
    {
        $value = data_get($payload, 'data.batchId');

        return $value === null ? null : (string) $value;
    }

    private function payloadDisplayName(array $payload): ?string
    {
        $displayName = $payload['displayName'] ?? null;

        if (is_string($displayName) && $displayName !== '') {
            return $displayName;
        }

        $commandName = data_get($payload, 'data.commandName');

        return is_string($commandName) && $commandName !== '' ? $commandName : null;
    }

    private function extractChunkMeta(array $payload, string $payloadText): array
    {
        $current = $this->firstIntValue($payload, [
            'currentChunk', 'chunkIndex', 'chunk', 'currentBatch', 'batchNo', 'batch_no',
            'data.currentChunk', 'data.chunkIndex', 'data.chunk', 'data.currentBatch', 'data.batchNo', 'data.batch_no',
            'variables.currentChunk', 'variables.chunkIndex', 'variables.chunk', 'variables.currentBatch', 'variables.batchNo', 'variables.batch_no',
        ]);
        $total = $this->firstIntValue($payload, [
            'totalChunks', 'chunks', 'chunkCount', 'totalBatches', 'batchCount',
            'data.totalChunks', 'data.chunks', 'data.chunkCount', 'data.totalBatches', 'data.batchCount',
            'variables.totalChunks', 'variables.chunks', 'variables.chunkCount', 'variables.totalBatches', 'variables.batchCount',
        ]);

        $current ??= $this->firstRegexInt($payloadText, ['currentChunk', 'chunkIndex', 'currentBatch', 'batchNo', 'batch_no']);
        $total ??= $this->firstRegexInt($payloadText, ['totalChunks', 'chunks', 'chunkCount', 'totalBatches', 'batchCount']);

        if ($current !== null && $total !== null && $current >= 0 && $current < $total) {
            $current++;
        }

        $label = ($current !== null && $total !== null && $total > 0)
            ? __('Chunk :current of :total', ['current' => $current, 'total' => $total])
            : null;

        return ['current' => $current, 'total' => $total, 'label' => $label];
    }

    private function firstIntValue(array $payload, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function firstRegexInt(string $text, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (preg_match("/[\"']".preg_quote($key, '/')."[\"']\\s*(?:=>|:|,)\\s*([0-9]+)/i", $text, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function jobStatusLabel(?string $status): string
    {
        return match ($status) {
            'reserved', 'running' => __('Running'),
            'pending' => __('Waiting'),
            'failed' => __('Failed'),
            'completed', 'finished' => __('Finished'),
            default => __('Queued'),
        };
    }

    private function humanizeName(string $name): string
    {
        $name = class_basename($name);
        $name = Str::of($name)
            ->replace(['_', '-'], ' ')
            ->headline()
            ->replace('M3U', 'M3U')
            ->replace('Tmdb', 'TMDB')
            ->replace('Strm', 'STRM')
            ->trim();

        return (string) $name;
    }

    private function durationLabel(int|float $seconds): string
    {
        $seconds = (int) max(0, round($seconds));

        if ($seconds < 60) {
            return __(':seconds sec.', ['seconds' => $seconds]);
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return __(':minutes min.', ['minutes' => $minutes]);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0
            ? __(':hours hr. :minutes min.', ['hours' => $hours, 'minutes' => $remainingMinutes])
            : __(':hours hr.', ['hours' => $hours]);
    }
}
