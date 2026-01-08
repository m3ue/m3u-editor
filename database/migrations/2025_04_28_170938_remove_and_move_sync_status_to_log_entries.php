<?php

use App\Models\PlaylistSyncStatus;
use App\Models\PlaylistSyncStatusLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * Get the existing column data and create separate log entries...
         *
         * 1. Get the existing column data
         * 2. Create separate log entries
         * 3. Remove the old columns
         */

        // 1. Get the existing column data
        $syncs = PlaylistSyncStatus::query()
            ->select('id', 'user_id', 'playlist_id', 'deleted_groups', 'added_groups', 'deleted_channels', 'added_channels')
            ->whereNotNull('deleted_groups')
            ->orWhereNotNull('added_groups')
            ->orWhereNotNull('deleted_channels')
            ->orWhereNotNull('added_channels');

        // 2. Create separate log entries
        if ($syncs->count() > 0) {
            foreach ($syncs->cursor() as $sync) {
                $logEntries = [];
                $logFields = [
                    'user_id' => $sync->user_id,
                    'playlist_id' => $sync->playlist_id,
                    'playlist_sync_status_id' => $sync->id,
                ];
                if ($sync->deleted_groups) {
                    foreach ($sync->deleted_groups as $group) {
                        $logEntries[] = [
                            ...$logFields,
                            'name' => $group['name'],
                            'type' => 'group',
                            'status' => 'removed',
                            'meta' => json_encode($group),
                        ];
                    }
                }
                if ($sync->added_groups) {
                    foreach ($sync->added_groups as $group) {
                        $logEntries[] = [
                            ...$logFields,
                            'name' => $group['name'],
                            'type' => 'group',
                            'status' => 'added',
                            'meta' => json_encode($group),
                        ];
                    }
                }
                if ($sync->deleted_channels) {
                    foreach ($sync->deleted_channels as $channel) {
                        $logEntries[] = [
                            ...$logFields,
                            'name' => $channel['title'],
                            'type' => 'channel',
                            'status' => 'removed',
                            'meta' => json_encode($channel),
                        ];
                    }
                }
                if ($sync->added_channels) {
                    foreach ($sync->added_channels as $channel) {
                        $logEntries[] = [
                            ...$logFields,
                            'name' => $channel['title'],
                            'type' => 'channel',
                            'status' => 'added',
                            'meta' => json_encode($channel),
                        ];
                    }
                }

                // Insert the log entries
                PlaylistSyncStatusLog::insert($logEntries);
            }
        }

        // 3. Remove the old columns
        Schema::table('playlist_sync_statuses', function (Blueprint $table) {
            $table->dropColumn([
                'deleted_groups',
                'added_groups',
                'deleted_channels',
                'added_channels',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
