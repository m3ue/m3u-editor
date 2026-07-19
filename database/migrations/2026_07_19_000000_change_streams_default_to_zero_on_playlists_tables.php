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
        Schema::table('playlists', function (Blueprint $table) {
            $table->integer('streams')->default(0)->change();
        });

        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->integer('streams')->default(0)->change();
        });

        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->integer('streams')->default(0)->change();
        });

        // Update existing playlists that are using the old default of 1 stream.
        // This allows them to correctly fall back to the provider's max_connections.
        DB::table('playlists')->where('streams', 1)->update(['streams' => 0]);
        DB::table('custom_playlists')->where('streams', 1)->update(['streams' => 0]);
        DB::table('merged_playlists')->where('streams', 1)->update(['streams' => 0]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert existing records updated to 0 back to 1.
        DB::table('playlists')->where('streams', 0)->update(['streams' => 1]);
        DB::table('custom_playlists')->where('streams', 0)->update(['streams' => 1]);
        DB::table('merged_playlists')->where('streams', 0)->update(['streams' => 1]);

        Schema::table('playlists', function (Blueprint $table) {
            $table->integer('streams')->default(1)->change();
        });

        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->integer('streams')->default(1)->change();
        });

        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->integer('streams')->default(1)->change();
        });
    }
};
