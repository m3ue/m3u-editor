<?php

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
        Schema::table('epgs', function (Blueprint $table) {
            // Optional "same provider" tie between a provider playlist and its
            // provider EPG. Used to keep the EPG URL in sync with the playlist's
            // DNS failover and to scope optimized import. Nullable: EPGs may come
            // from a different source than any playlist.
            $table->foreignId('playlist_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->nullOnDelete()
                ->index();

            // When enabled (only meaningful with playlist_id set), EPG cache
            // generation only materializes programme data for channels the tied
            // playlist(s) actually map. The full channel list is still imported.
            $table->boolean('optimize_import')
                ->default(false)
                ->after('playlist_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('playlist_id');
            $table->dropColumn('optimize_import');
        });
    }
};
