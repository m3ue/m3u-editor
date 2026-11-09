<?php

use App\Filament\Resources\QueueMonitor\Pages\ListQueueMonitors;
use App\Models\QueueMonitor;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

function insertFailedJobRecord(string $uuid): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['uuid' => $uuid]),
        'exception' => 'Test exception',
        'failed_at' => now(),
    ]);
}

test('retry does not claim success when the failed job no longer exists', function () {
    $monitor = QueueMonitor::create([
        'job_id' => 'missing-job-uuid',
        'name' => 'App\\Jobs\\AddItemsToCustomPlaylistChunk',
        'queue' => 'default',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'failed' => true,
        'attempt' => 1,
    ]);

    Livewire::test(ListQueueMonitors::class)
        ->callAction(TestAction::make('retry')->table($monitor))
        ->assertNotified(__('Failed job is no longer available to retry'));

    expect(DB::table('jobs')->count())->toBe(0);
});

test('retry queues the job when the failed payload is still available', function () {
    insertFailedJobRecord('available-job-uuid');

    $monitor = QueueMonitor::create([
        'job_id' => 'available-job-uuid',
        'name' => 'App\\Jobs\\AddItemsToCustomPlaylistChunk',
        'queue' => 'default',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'failed' => true,
        'attempt' => 1,
    ]);

    Livewire::test(ListQueueMonitors::class)
        ->callAction(TestAction::make('retry')->table($monitor))
        ->assertNotified(__('Job queued for retry'));

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->where('uuid', 'available-job-uuid')->exists())->toBeFalse();
});
