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
