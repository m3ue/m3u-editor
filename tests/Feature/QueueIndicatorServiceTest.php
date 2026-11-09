<?php

use App\Services\QueueIndicatorService;
use Illuminate\Support\Facades\DB;

function insertJobBatchFixture(string $id, bool $finished, int $pendingJobs = 0): void
{
    DB::table('job_batches')->insert([
        'id' => $id,
        'name' => "batch-{$id}",
        'total_jobs' => 1,
        'pending_jobs' => $pendingJobs,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => serialize([]),
        'cancelled_at' => null,
        'created_at' => now()->timestamp,
        'finished_at' => $finished ? now()->timestamp : null,
    ]);
}

it('keeps an older pending batch visible even when newer batches have all completed', function () {
    // The oldest id ("b00") is the still-pending batch; everything after it,
    // lexically larger and therefore returned first by the newest-first
    // `id desc` ordering, is a completed/newer batch.
    insertJobBatchFixture('b00', finished: false, pendingJobs: 1);

    for ($i = 1; $i <= 10; $i++) {
        insertJobBatchFixture('b'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), finished: true);
    }

    $snapshot = app(QueueIndicatorService::class)->getSnapshot(limit: 10);

    $batchIds = collect($snapshot['batches'] ?? [])->pluck('id');

    expect($batchIds)->toContain('b00');
});

it('respects the configured limit once enough active batches are found', function () {
    for ($i = 1; $i <= 3; $i++) {
        insertJobBatchFixture('p0'.$i, finished: false, pendingJobs: 1);
    }

    $snapshot = app(QueueIndicatorService::class)->getSnapshot(limit: 2);

    expect($snapshot['batches'])->toHaveCount(2);
});
