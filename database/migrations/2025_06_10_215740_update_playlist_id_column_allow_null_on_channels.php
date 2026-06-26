<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On Postgres, use IF EXISTS so this is safe on a bare schema (e.g. migrated from SQLite)
        // where the FK may not have been created by the original migration chain.
        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE "channels" DROP CONSTRAINT IF EXISTS "channels_playlist_id_foreign"');
        }

        Schema::table('channels', function (Blueprint $table) {
            if (config('database.default') !== 'pgsql') {
                $table->dropForeign(['playlist_id']);
            }

            $table->unsignedBigInteger('playlist_id')->nullable()->change();
            $table->foreign('playlist_id')->references('id')->on('playlists')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['playlist_id']);
            $table->unsignedBigInteger('playlist_id')->nullable(false)->change();
            $table->foreign('playlist_id')->references('id')->on('playlists')->onDelete('cascade')->onUpdate('cascade');
        });
    }
};
