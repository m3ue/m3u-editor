<?php

namespace App\Models;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class QueueMonitor extends Model
{
    use MassPrunable;

    protected $table = 'queue_monitor';

    protected $casts = [
        'failed' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function status(): Attribute
    {
        return Attribute::make(
            get: fn (): string => match (true) {
                $this->failed => 'failed',
                $this->finished_at !== null => 'succeeded',
                default => 'running',
            }
        );
    }

    public function durationMs(): Attribute
    {
        return Attribute::make(
            get: function (): ?int {
                if ($this->started_at === null || $this->finished_at === null) {
                    return null;
                }

                return (int) $this->started_at->diffInMilliseconds($this->finished_at);
            }
        );
    }

    public function isFinished(): bool
    {
        return $this->failed || $this->finished_at !== null;
    }

    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<=', now()->subDays(config('queue-monitor.retention_days', 7)));
    }

    public static function getJobId(JobContract $job): string
    {
        return $job->payload()['uuid'] ?? Hash::make($job->getRawBody());
    }
}
