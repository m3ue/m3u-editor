<?php

namespace App\Services;

use App\Facades\SortFacade;
use App\Jobs\SyncPlexDvrJob;
use App\Models\Channel;
use App\Models\Group;
use Illuminate\Database\Eloquent\Builder;

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
    /**
     * @param  bool  $dispatchSync  Set false when calling this in a loop (e.g. a
     *                              bulk action over several groups) so the caller can dispatch a single
     *                              SyncPlexDvrJob after the loop instead of one per group.
     */
    public function enable(Group $group, bool $dispatchSync = true): void
    {
        $this->channelsQuery($group)->update(['enabled' => true]);

        if ($group->type !== 'live') {
            return;
        }

        $maxChannel = Channel::query()
            ->where('playlist_id', $group->playlist_id)
            ->where('group_id', '!=', $group->id)
            ->where('enabled', true)
            ->max('channel') ?? 0;

        // A merged group never owns channels directly, so the numeric-sort recount
        // (which matches on group_id) has nothing to renumber here; the descendant
        // channels keep the numbering they already had under their own child group.
        if (! $group->is_merged) {
            SortFacade::bulkRecountGroupChannels($group, $maxChannel + 1);
        }

        if ($dispatchSync) {
            SyncPlexDvrJob::dispatchIfConfigured(trigger: 'group_enable');
        }
    }

    /**
     * @param  bool  $dispatchSync  See {@see self::enable()}.
     */
    public function disable(Group $group, bool $dispatchSync = true): void
    {
        $this->channelsQuery($group)->update(['enabled' => false]);

        if ($group->type !== 'live') {
            return;
        }

        if ($dispatchSync) {
            SyncPlexDvrJob::dispatchIfConfigured(trigger: 'group_disable');
        }
    }

    /**
     * Channels this state change should affect: a merged group's descendants when
     * given a merged parent (which never owns channels directly), otherwise the
     * group's own channels.
     */
    private function channelsQuery(Group $group): Builder
    {
        if ($group->is_merged) {
            return Channel::query()->whereIn('group_id', $group->children()->pluck('id'));
        }

        return $group->channels()->getQuery();
    }
}
