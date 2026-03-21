<?php

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
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
            $table->boolean('enable_logo_proxy')
                ->default(false)
                ->after('enable_proxy');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('enable_logo_proxy')
                ->default(false)
                ->after('enable_proxy');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('enable_logo_proxy')
                ->default(false)
                ->after('enable_proxy');
        });

        // Need to update the existing playlists to have this enabled when they have proxy enabled
        $playlists = Playlist::where('enable_proxy', true);
        foreach ($playlists->cursor() as $playlist) {
            $playlist->enable_logo_proxy = true;
            $playlist->save();
        }
        $custom_playlists = CustomPlaylist::where('enable_proxy', true);
        foreach ($custom_playlists->cursor() as $playlist) {
            $playlist->enable_logo_proxy = true;
            $playlist->save();
        }
        $merged_playlists = MergedPlaylist::where('enable_proxy', true);
        foreach ($merged_playlists->cursor() as $playlist) {
            $playlist->enable_logo_proxy = true;
            $playlist->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('enable_logo_proxy');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('enable_logo_proxy');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('enable_logo_proxy');
        });
    }
};
