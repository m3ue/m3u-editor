<?php

namespace App\Observers;

use App\Jobs\SyncPlexDvrJob;
use App\Models\Channel;
use App\Models\DvrRecording;
use Illuminate\Support\Facades\DB;

class ChannelObserver
{
    /**
     * Handle the Channel "updated" event.
     *
     * Dispatches a Plex DVR sync when the enabled status changes.
     * SyncPlexDvrJob is ShouldBeUnique (60s window), so rapid
     * individual toggles are automatically debounced.
     */
    public function updated(Channel $channel): void
    {
        if ($channel->wasChanged('enabled')) {
            dispatch(new SyncPlexDvrJob(trigger: 'channel_observer'));
        }
    }

    /**
     * Handle the Channel "deleting" event.
     *
     * When a DVR-created VOD channel is deleted, cascade the deletion to the
     * linked DvrRecording (which in turn deletes the file and any VOD episode).
     *
     * We null dvr_recording_id in the DB before deleting the recording so that
     * DvrRecording::deleting cannot loop back and try to delete this channel again.
     */
    public function deleting(Channel $channel): void
    {
        if (! $channel->dvr_recording_id) {
            return;
        }

        $recordingId = $channel->dvr_recording_id;

        // Break the bi-directional link in the DB before cascading, so
        // DvrRecording::deleting won't find this channel and re-delete it.
        DB::table('channels')->where('id', $channel->id)->update(['dvr_recording_id' => null]);

        $recording = DvrRecording::find($recordingId);
        $recording?->delete();
    }
}
