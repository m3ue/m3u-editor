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
        DB::statement(
            'UPDATE media_requests SET status = ?, reviewed_at = ? WHERE id IN ('
            .'SELECT id FROM ('
            .'SELECT id, ROW_NUMBER() OVER ('
            .'PARTITION BY playlist_auth_id, arr_integration_id, external_id, request_type'
            .' ORDER BY CASE WHEN status = ? THEN 0 ELSE 1 END, id DESC'
            .') AS rn'
            .' FROM media_requests WHERE status IN (?, ?)'
            .' AND playlist_auth_id IS NOT NULL AND external_id IS NOT NULL'
            .') ranked WHERE rn > 1'
            .')',
            ['rejected', now()->toDateTimeString(), 'approved', 'pending', 'approved']
        );

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
