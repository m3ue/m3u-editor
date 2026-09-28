<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('provider_auth_passthrough')
                ->default(false);

            $table->boolean('provider_auth_passthrough_live')
                ->default(true);

            $table->boolean('provider_auth_passthrough_vod')
                ->default(false);

            $table->boolean('provider_auth_passthrough_series')
                ->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn([
                'provider_auth_passthrough',
                'provider_auth_passthrough_live',
                'provider_auth_passthrough_vod',
                'provider_auth_passthrough_series',
            ]);
        });
    }
};
