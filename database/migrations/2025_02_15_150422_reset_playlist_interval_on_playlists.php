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
        DB::table('playlists')
            ->update(['sync_interval' => '24 hours']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
