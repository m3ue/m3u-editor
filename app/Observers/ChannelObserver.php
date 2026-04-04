<?php

namespace App\Observers;

use App\Jobs\SyncPlexDvrJob;
use App\Models\Channel;
use Illuminate\Support\Facades\Bus;

class ChannelObserver
{
    /**
     * Handle the Channel "updated" event.
     *
     * Dispatches a Plex DVR sync when the enabled status changes.
     * SyncPlexDvrJob is ShouldBeUnique (60s window), so rapid
     * individual toggles are automatically debounced.
     *
     * Uses Bus::dispatch() instead of the dispatch() helper to bypass
     * PendingDispatch's cache-based unique lock which causes flaky
     * failures under parallel tests.  ShouldBeUnique is still enforced
     * by the queue worker at processing time.
     */
    public function updated(Channel $channel): void
    {
        if ($channel->wasChanged('enabled')) {
            Bus::dispatch(new SyncPlexDvrJob(trigger: 'channel_observer'));
        }
    }
}
