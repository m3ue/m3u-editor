<?php

use App\Models\Group;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Uses the query builder against the `channels` table directly, rather than the
     * Channel Eloquent model (whereHas('vod_channels')/where('is_vod', ...) below were
     * originally written against the model) — migrations run against the schema as it
     * existed at that point in history, but an Eloquent model reflects the CURRENT
     * class definition (including any global scopes added since), which can reference
     * columns that don't exist yet during a full from-scratch migration replay (e.g.
     * tests' RefreshDatabase).
     */
    public function up(): void
    {
        // 1. Get groups that have VOD channels
        $vodGroupIds = DB::table('channels')
            ->where('is_vod', true)
            ->whereNotNull('group_id')
            ->distinct()
            ->pluck('group_id');
        $vodGroups = Group::query()->whereIn('id', $vodGroupIds)->get();

        // 2. Determine if we need to create a new VOD group or update existing
        foreach ($vodGroups as $group) {
            $hasLiveChannels = DB::table('channels')
                ->where('group_id', $group->id)
                ->where('is_vod', false)
                ->exists();

            // 2.1: If has live channels too, need to migrate this to a VOD group
            if ($hasLiveChannels) {
                // Need to replicate this group for VOD
                $vodGroup = $group->replicate();
                $vodGroup->type = 'vod';
                $vodGroup->pushQuietly();

                DB::table('channels')
                    ->where('is_vod', true)
                    ->where('group_id', $group->id)
                    ->update(['group_id' => $vodGroup->id]);
            } else {
                // 2.2: VOD only group, just update the type
                $group->update(['type' => 'vod']);
            }
        }

        // 3. Finally, let's clear out "live" groups that have no channels associated with them
        $liveGroupIdsWithChannels = DB::table('channels')
            ->where('is_vod', false)
            ->whereNotNull('group_id')
            ->distinct()
            ->pluck('group_id');

        Group::where('type', 'live')
            ->where('custom', false) // Only delete non-custom groups
            ->whereNotIn('id', $liveGroupIdsWithChannels)
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
