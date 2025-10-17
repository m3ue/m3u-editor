<?php

use App\Models\Playlist;
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
        Schema::table('playlists', function (Blueprint $table) {
            $table->string('source_type')->nullable()->after('xtream')->comment('Source type: xtream, emby (Emby/Jellyfin compatible), m3u, local');
        });

        // Update existing records to have a default source_type if xtream is true
        Playlist::where('xtream', true)->update(['source_type' => 'xtream']);

        // All other existing records should be 'm3u'
        Playlist::where('xtream', false)->update(['source_type' => 'm3u']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('source_type');
        });
    }
};
