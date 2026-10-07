<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every playlist type that accepts the owner's "default" login.
     */
    private const TABLES = ['playlists', 'merged_playlists', 'custom_playlists', 'playlist_aliases'];

    /**
     * Run the migrations.
     *
     * A constant default and a nullable column with no index are metadata-only on
     * Postgres 11+, so neither rewrites nor long-locks the tables the sync jobs write to.
     * The 'uuid' default keeps the existing owner + UUID login for every playlist.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('default_auth_mode')->default('uuid');
                $table->string('default_auth_password')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['default_auth_mode', 'default_auth_password']);
            });
        }
    }
};
