<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add flag to identify network playlists
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('is_network_playlist')->default(false)->after('enabled');
        });

        // Add FK from networks to their output playlist
        Schema::table('networks', function (Blueprint $table) {
            $table->foreignId('network_playlist_id')->nullable()->after('media_server_integration_id')
                ->constrained('playlists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('network_playlist_id');
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('is_network_playlist');
        });
    }
};
