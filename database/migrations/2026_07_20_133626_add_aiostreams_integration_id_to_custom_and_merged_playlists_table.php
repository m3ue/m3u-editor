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
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->foreignId('aiostreams_integration_id')
                ->nullable()
                ->constrained('media_server_integrations')
                ->nullOnDelete();
        });

        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->foreignId('aiostreams_integration_id')
                ->nullable()
                ->constrained('media_server_integrations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropForeign(['aiostreams_integration_id']);
            $table->dropColumn('aiostreams_integration_id');
        });

        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropForeign(['aiostreams_integration_id']);
            $table->dropColumn('aiostreams_integration_id');
        });
    }
};
