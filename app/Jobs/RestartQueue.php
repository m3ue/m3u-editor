<?php

namespace App\Jobs;

use Exception;
use App\Enums\Status;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

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
                    'processing' => [
                        'live_processing' => false,
                        'vod_processing' => false,
                        'series_processing' => false,
                    ],
                    'progress' => 0,
                    'series_progress' => 0,
                    'vod_progress' => 0,
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
                    'sd_progress' => 0,
                    'cache_progress' => 0,
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
                    'errors' => 'The EPG mapping process was interrupted and has been marked as failed.',
                ]);
        } catch (Exception $e) {
            Log::error('Failed to restart queue: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
