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
     * A constant default and nullable columns with no index are metadata-only on
     * Postgres 11+, so none rewrites or long-locks the tables the sync jobs write to.
     * The 'uuid' default keeps the existing owner + UUID login for every playlist.
     * internal_auth_secret is mixed into the internal token and rotated whenever the
     * default login changes, so links built with an older token stop working.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('default_auth_mode')->default('uuid');
                $table->string('default_auth_password')->nullable();
                $table->string('internal_auth_secret')->nullable();
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
                $table->dropColumn(['default_auth_mode', 'default_auth_password', 'internal_auth_secret']);
            });
        }
    }
};
