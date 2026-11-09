<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Emby/Jellyfin integrations can authenticate with a regular user's
     * username and password instead of an admin-generated API key. The
     * password is exchanged once for a session token (stored in api_key) and
     * never persisted; server_user_id scopes library and item queries to
     * that user.
     */
    public function up(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->string('auth_method')->default('api_key')->after('api_key');
            $table->string('server_username')->nullable()->after('auth_method');
            $table->string('server_user_id')->nullable()->after('server_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn(['auth_method', 'server_username', 'server_user_id']);
        });
    }
};
