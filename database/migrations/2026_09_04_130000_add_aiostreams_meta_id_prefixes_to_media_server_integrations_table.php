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
        Schema::table('media_server_integrations', function (Blueprint $table) {
            // Null = capability unknown (never synced under this flag; treated as
            // "try it"). Empty array = the manifest declares no `meta` resource at
            // all. A populated array holds the id prefixes ("tt", "kitsu:", ...)
            // the manifest's `meta` resource declares support for, or ["*"] when
            // it's declared without any idPrefixes restriction. Computed from the
            // manifest on test-connection/sync — see AIOStreamsService::testConnection().
            $table->jsonb('aiostreams_meta_id_prefixes')->nullable()->after('aiostreams_selected_catalog_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn('aiostreams_meta_id_prefixes');
        });
    }
};
