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
            $table->boolean('aiostreams_enable_all_catalogs')->default(true)->after('aiostreams_logo');
            $table->jsonb('aiostreams_selected_catalog_ids')->nullable()->after('aiostreams_enable_all_catalogs');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn(['aiostreams_enable_all_catalogs', 'aiostreams_selected_catalog_ids']);
        });
    }
};
