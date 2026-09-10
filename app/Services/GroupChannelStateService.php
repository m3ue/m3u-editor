<?php

namespace App\Services;

use App\Facades\SortFacade;
use App\Jobs\SyncPlexDvrJob;
use App\Models\Channel;
use App\Models\Group;

/**
 * Enable/disable every channel in a group, matching the canonical behaviour of
 * GroupResource's (live) and VodGroupResource's (vod) single-record actions:
 * live groups also get freshly-enabled channels renumbered to the end of the
 * playlist's live lineup and a Plex/HDHR DVR resync; vod groups just flip the
 * flag. Centralised here so every surface that offers this action - the
 * resources and the Easy Editor - shares one implementation instead of each
 * reimplementing (and potentially drifting from) it.
 */
class GroupChannelStateService
{
    public function enable(Group $group): void
    {
        $group->channels()->update(['enabled' => true]);

        if ($group->type !== 'live') {
            return;
        }

        $maxChannel = Channel::query()
            ->where('playlist_id', $group->playlist_id)
            ->where('group_id', '!=', $group->id)
            ->where('enabled', true)
            ->max('channel') ?? 0;

        SortFacade::bulkRecountGroupChannels($group, $maxChannel + 1);

        SyncPlexDvrJob::dispatchIfConfigured(trigger: 'group_enable');
    }

    public function disable(Group $group): void
    {
        $group->channels()->update(['enabled' => false]);

        if ($group->type !== 'live') {
            return;
        }

        SyncPlexDvrJob::dispatchIfConfigured(trigger: 'group_disable');
    }
}
