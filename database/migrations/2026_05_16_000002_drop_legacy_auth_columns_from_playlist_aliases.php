<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PLAYLIST_ALIAS_TYPE = 'App\\Models\\PlaylistAlias';

    public function up(): void
    {
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $expiresAtIndex = collect(Schema::getIndexes('playlist_aliases'))
                ->first(fn (array $index): bool => $index['columns'] === ['expires_at']);

            if ($expiresAtIndex) {
                $table->dropIndex($expiresAtIndex['name']);
            }

            $table->dropColumn(['username', 'password', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->string('username')->nullable()->after('priority');
            $table->string('password')->nullable()->after('username');
            $table->dateTime('expires_at')->nullable()->after('password')->index();
        });

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
};
