<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
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
                    $playlistAuthId = $this->findOrCreatePlaylistAuth($alias, $now);

                    if (! $playlistAuthId || ! $this->assignAuthToAlias($playlistAuthId, (int) $alias->id, $now)) {
                        continue;
                    }

                    DB::table('playlist_aliases')
                        ->where('id', $alias->id)
                        ->update([
                            'username' => null,
                            'password' => null,
                            'expires_at' => null,
                            'updated_at' => $now,
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('authenticatables')
            ->join('playlist_auths', 'authenticatables.playlist_auth_id', '=', 'playlist_auths.id')
            ->where('authenticatables.authenticatable_type', self::PLAYLIST_ALIAS_TYPE)
            ->orderBy('authenticatables.id')
            ->select([
                'authenticatables.authenticatable_id',
                'playlist_auths.username',
                'playlist_auths.password',
                'playlist_auths.expires_at',
            ])
            ->get()
            ->each(function ($assignment): void {
                DB::table('playlist_aliases')
                    ->where('id', $assignment->authenticatable_id)
                    ->whereNull('username')
                    ->update([
                        'username' => $assignment->username,
                        'password' => $assignment->password,
                        'expires_at' => $assignment->expires_at,
                    ]);
            });
    }

    private function findOrCreatePlaylistAuth(object $alias, Carbon $now): ?int
    {
        $existingAuth = DB::table('playlist_auths')
            ->where('username', $alias->username)
            ->first();

        if ($existingAuth) {
            if ($existingAuth->password !== $alias->password) {
                return null;
            }

            return (int) $existingAuth->id;
        }

        return DB::table('playlist_auths')->insertGetId([
            'name' => Str::limit('Migrated alias auth: '.$alias->name, 255, ''),
            'enabled' => true,
            'user_id' => $alias->user_id,
            'username' => $alias->username,
            'password' => $alias->password,
            'expires_at' => $alias->expires_at,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assignAuthToAlias(int $playlistAuthId, int $aliasId, Carbon $now): bool
    {
        $existingAssignment = DB::table('authenticatables')
            ->where('playlist_auth_id', $playlistAuthId)
            ->first();

        if ($existingAssignment) {
            return $existingAssignment->authenticatable_type === self::PLAYLIST_ALIAS_TYPE
                && (int) $existingAssignment->authenticatable_id === $aliasId;
        }

        DB::table('authenticatables')->insert([
            'playlist_auth_id' => $playlistAuthId,
            'authenticatable_type' => self::PLAYLIST_ALIAS_TYPE,
            'authenticatable_id' => $aliasId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }
};
