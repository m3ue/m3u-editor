<?php

use App\Models\Channel;
use App\Models\Group;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Get groups that have VOD channels
        $vodGroups = Group::query()
            ->whereHas('vod_channels')
            ->get();

        // 2. Determine if we need to create a new VOD group or update existing
        foreach ($vodGroups as $group) {
            // 2.1: If has live channels too, need to migrate this to a VOD group
            if ($group->live_channels()->count() > 0) {
                // Need to replicate this group for VOD
                $vodGroup = $group->replicate();
                $vodGroup->type = 'vod';
                $vodGroup->pushQuietly();

                Channel::where('is_vod', true)
                    ->where('group_id', $group->id)
                    ->update(['group_id' => $vodGroup->id]);
            } else {
                // 2.2: VOD only group, just update the type
                $group->update(['type' => 'vod']);
            }
        }

        // 3. Finally, let's clear out "live" groups that have no channels associated with them
        Group::where('type', 'live')
            ->where('custom', false) // Only delete non-custom groups
            ->whereDoesntHave('live_channels')
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
