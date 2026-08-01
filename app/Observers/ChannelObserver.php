<?php

namespace App\Observers;

use App\Jobs\SyncPlexDvrJob;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\DvrRecording;
use App\Models\Group;
use App\Models\Scopes\ExcludeAioFailoverClonesScope;
use Illuminate\Support\Facades\DB;

class ChannelObserver
{
    /**
     * Handle the Channel "creating" event.
     *
     * Inherit the parent group's stream_profile_id when creating a new channel
     * that has not been assigned one explicitly. Bulk imports via Channel::upsert()
     * bypass model events; those paths inject the value into their payload directly.
     */
    public function creating(Channel $channel): void
    {
        if ($channel->stream_profile_id !== null || $channel->group_id === null) {
            return;
        }

        $defaultProfileId = Group::query()
            ->whereKey($channel->group_id)
            ->value('stream_profile_id');

        if ($defaultProfileId !== null) {
            $channel->stream_profile_id = $defaultProfileId;
        }
    }

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
            SyncPlexDvrJob::dispatchIfConfigured(trigger: 'channel_observer');
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
        // AIOStreams failover candidates are lightweight sibling Channel rows the
        // global ExcludeAioFailoverClonesScope normally hides — the FK cascade on
        // channel_failovers.channel_id only removes the pivot row when the PRIMARY
        // channel is deleted, not the clone row the pivot points at, so without this
        // they'd become permanently orphaned (invisible, but never cleaned up).
        if ($channel->is_custom && $channel->aio_integration_id && ! $channel->is_aio_failover_clone) {
            $failoverChannelIds = ChannelFailover::where('channel_id', $channel->id)->pluck('channel_failover_id');

            Channel::withoutGlobalScope(ExcludeAioFailoverClonesScope::class)
                ->whereIn('id', $failoverChannelIds)
                ->where('is_aio_failover_clone', true)
                ->delete();
        }

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
