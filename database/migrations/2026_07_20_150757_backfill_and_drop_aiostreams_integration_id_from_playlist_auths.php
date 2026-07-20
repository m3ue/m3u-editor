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
        // AIOStreams now follows the same model as DVR/Requests: the parent playlist
        // (Playlist/CustomPlaylist/MergedPlaylist) owns the integration, and PlaylistAuth
        // only toggles per-guest access. Before dropping the old per-auth override
        // column, push any existing overrides onto the assigned playlist so guests who
        // relied on it keep working.
        $overrides = DB::table('playlist_auths')
            ->whereNotNull('aiostreams_integration_id')
            ->get(['id', 'aiostreams_integration_id']);

        foreach ($overrides as $auth) {
            $pivot = DB::table('authenticatables')
                ->where('playlist_auth_id', $auth->id)
                ->first();

            if (! $pivot) {
                continue;
            }

            $table = match ($pivot->authenticatable_type) {
                'custom_playlist', 'App\\Models\\CustomPlaylist' => 'custom_playlists',
                'merged_playlist', 'App\\Models\\MergedPlaylist' => 'merged_playlists',
                'playlist', 'App\\Models\\Playlist' => 'playlists',
                // Aliases don't carry their own AIOStreams setting — nothing to backfill.
                default => null,
            };

            if (! $table) {
                continue;
            }

            DB::table($table)
                ->where('id', $pivot->authenticatable_id)
                ->whereNull('aiostreams_integration_id')
                ->update(['aiostreams_integration_id' => $auth->aiostreams_integration_id]);
        }

        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->dropForeign(['aiostreams_integration_id']);
            $table->dropColumn('aiostreams_integration_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->foreignId('aiostreams_integration_id')
                ->nullable()
                ->after('aiostreams_enabled')
                ->constrained('media_server_integrations')
                ->nullOnDelete();
        });
    }
};
