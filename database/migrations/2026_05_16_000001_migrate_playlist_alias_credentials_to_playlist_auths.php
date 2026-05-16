<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PLAYLIST_ALIAS_TYPE = 'App\\Models\\PlaylistAlias';

    public function up(): void
    {
        $now = now();

        DB::table('playlist_aliases')
            ->select(['id', 'user_id', 'name', 'username', 'password', 'expires_at'])
            ->whereNotNull('username')
            ->where('username', '!=', '')
            ->whereNotNull('password')
            ->where('password', '!=', '')
            ->chunkById(100, function ($aliases) use ($now): void {
                foreach ($aliases as $alias) {
                    $playlistAuthId = DB::table('playlist_auths')->insertGetId([
                        'name' => Str::limit('Migrated alias auth: '.$alias->name, 255, ''),
                        'enabled' => true,
                        'user_id' => $alias->user_id,
                        'username' => $alias->username,
                        'password' => $alias->password,
                        'expires_at' => $alias->expires_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('authenticatables')->insert([
                        'playlist_auth_id' => $playlistAuthId,
                        'authenticatable_type' => self::PLAYLIST_ALIAS_TYPE,
                        'authenticatable_id' => $alias->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally do not delete generated Playlist Auth records on rollback.
        // They may have been edited or used after the upgrade.
    }
};
