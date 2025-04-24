<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class RestartQueue implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Restart the queue and flush any pending jobs
            Artisan::call('app:restart-queue');

            // Reset Playlist and EPGs that were in a processing state
            Playlist::where('status', Status::Processing)
                ->orWhere('processing', true)
                ->update([
                    'status' => Status::Pending,
                    'processing' => false,
                    'progress' => 0,
                    'channels' => 0,
                    'synced' => null,
                    'errors' => null,
                ]);
            Epg::where('status', Status::Processing)
                ->orWhere('processing', true)
                ->update([
                    'status' => Status::Pending,
                    'processing' => false,
                    'progress' => 0,
                    'synced' => null,
                    'errors' => null,
                ]);

            // Update EPG Maps
            EpgMap::where('status', Status::Processing)
                ->orWhere('processing', true)
                ->update([
                    'status' => Status::Failed,
                    'processing' => false,
                    'progress' => 0,
                    'synced' => null,
                    'errors' => 'The EPG mapping process was interrupted and has been marked as failed.',
                ]);
        } catch (\Exception $e) {
            // Ignore
        }
    }
}
