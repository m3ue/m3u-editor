<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->foreignId('aiostreams_integration_id')
                ->nullable()
                ->after('dummy_epg_fallback_order')
                ->constrained('media_server_integrations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropForeign(['aiostreams_integration_id']);
            $table->dropColumn('aiostreams_integration_id');
        });
    }
};
