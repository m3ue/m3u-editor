<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Keep at most one active (pending/approved) request per
        // playlist_auth_id + arr_integration_id + external_id + request_type,
        // preferring approved over pending, then the most recent. Everything
        // else in a duplicate group is rejected so the unique index below
        // can be created without violating existing data.
        DB::table('media_requests')
            ->whereIn('status', ['pending', 'approved'])
            ->whereNotNull('playlist_auth_id')
            ->whereNotNull('external_id')
            ->orderByDesc('requested_at')
            ->orderBy('id')
            ->get(['id', 'playlist_auth_id', 'arr_integration_id', 'external_id', 'request_type', 'status'])
            ->groupBy(fn ($row) => implode(':', [
                $row->playlist_auth_id,
                $row->arr_integration_id,
                $row->external_id,
                $row->request_type,
            ]))
            ->each(function ($group) {
                $keep = $group->sortByDesc(fn ($row) => $row->status === 'approved')->first();

                $staleIds = $group->reject(fn ($row) => $row->id === $keep->id)->pluck('id');
                if ($staleIds->isNotEmpty()) {
                    DB::table('media_requests')
                        ->whereIn('id', $staleIds)
                        ->update(['status' => 'rejected', 'reviewed_at' => now()]);
                }
            });

        // No Schema Builder support for partial/filtered unique indexes;
        // this syntax is identical on the two supported drivers (sqlite, pgsql).
        DB::statement(
            'CREATE UNIQUE INDEX media_requests_active_unique'
            .' ON media_requests (playlist_auth_id, arr_integration_id, external_id, request_type)'
            ." WHERE status IN ('pending', 'approved')"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS media_requests_active_unique');
    }
};
