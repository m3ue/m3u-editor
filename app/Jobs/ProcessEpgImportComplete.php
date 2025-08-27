<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\EpgChannel;
use App\Models\Job;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEpgImportComplete implements ShouldQueue
{
    use Queueable;

    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int    $userId,
        public int    $epgId,
        public string $batchNo,
        public Carbon $start,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Calculate the time taken to complete the import
        $completedIn = $this->start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);

        $user = User::find($this->userId);
        $epg = $user->epgs()->find($this->epgId);

        // Send notification
        Notification::make()
            ->success()
            ->title('EPG Synced')
            ->body("\"{$epg->name}\" has been synced successfully.")
            ->broadcast($epg->user);
        Notification::make()
            ->success()
            ->title('EPG Synced')
            ->body("\"{$epg->name}\" has been synced successfully. Import completed in {$completedInRounded} seconds.")
            ->sendToDatabase($epg->user);

        // Clear out invalid channels (if any)
        EpgChannel::where([
            ['epg_id', $epg->id],
            ['import_batch_no', '!=', $this->batchNo],
        ])->delete();

        // Clear out the jobs
        Job::where(['batch_no', $this->batchNo])->delete();

        // Update the epg
        $epg->update([
            'status' => Status::Completed,
            'synced' => now(),
            'errors' => null,
            'sync_time' => $completedIn,
            'progress' => 100,
            'processing' => false,
        ]);

        // Check if there are any sync jobs that should be re-run
        $epg->epgMaps()->where([
            ['recurring', '=', true],
            ['playlist_id', '!=', null],
        ])->get()->each(function ($map) {
            dispatch(new MapPlaylistChannelsToEpg(
                epg: $map->epg_id,
                playlist: $map->playlist_id,
                epgMapId: $map->id,
            ));
        });

        // Fire the epg synced event
        event(new SyncCompleted($epg));
    }
}
