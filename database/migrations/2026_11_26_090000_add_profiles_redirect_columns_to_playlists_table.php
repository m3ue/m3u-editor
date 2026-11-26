<?php

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
            // Provider Profiles "Redirect" mode: hand players a 302 to the selected
            // account's provider URL instead of routing the stream through m3u-proxy.
            $table->boolean('profiles_redirect')->default(false);
            // Ask the provider (Xtream player_api active_cons) how many streams each
            // account is using, instead of counting handed-out redirects locally.
            $table->boolean('profiles_redirect_poll_provider')->default(true);
            // Polling mode: how long a provider's connection count is cached.
            $table->unsignedInteger('profiles_redirect_cache_seconds')->default(10);
            // Counting mode: how long a handed-out redirect occupies a slot.
            $table->unsignedInteger('profiles_redirect_hold_seconds')->default(3600);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn([
                'profiles_redirect',
                'profiles_redirect_poll_provider',
                'profiles_redirect_cache_seconds',
                'profiles_redirect_hold_seconds',
            ]);
        });
    }
};
