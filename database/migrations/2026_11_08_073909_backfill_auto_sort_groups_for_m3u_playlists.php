<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Plain M3U imports used to number groups from Channel Auto Sort and ignore Group Auto Sort.
     * They now follow Group Auto Sort, so copy each non-Xtream playlist's Channel Auto Sort value
     * across to keep its current group ordering behavior on the next sync.
     */
    public function up(): void
    {
        DB::table('playlists')
            ->where('xtream', false)
            ->update(['auto_sort_groups' => DB::raw('auto_sort')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nothing to undo: the previous import ignored auto_sort_groups for these playlists.
    }
};
