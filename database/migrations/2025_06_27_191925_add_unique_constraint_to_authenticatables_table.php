<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, remove duplicate records - keep only the most recent one for each playlist_auth_id
        // Use a database-agnostic approach that works with SQLite, MySQL, and PostgreSQL
        $duplicateIds = DB::table('authenticatables as a1')
            ->join('authenticatables as a2', function ($join) {
                $join->on('a1.playlist_auth_id', '=', 'a2.playlist_auth_id')
                    ->whereColumn('a1.id', '<', 'a2.id');
            })
            ->pluck('a1.id');

        if ($duplicateIds->isNotEmpty()) {
            DB::table('authenticatables')->whereIn('id', $duplicateIds)->delete();
        }

        // Now add the unique constraint
        Schema::table('authenticatables', function (Blueprint $table) {
            $table->unique('playlist_auth_id', 'unique_playlist_auth_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('authenticatables', function (Blueprint $table) {
            $table->dropUnique('unique_playlist_auth_id');
        });
    }
};
