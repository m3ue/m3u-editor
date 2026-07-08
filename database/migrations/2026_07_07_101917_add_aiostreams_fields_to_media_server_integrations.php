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
            $table->text('manifest_url')->nullable()->after('webdav_base_path');
            $table->jsonb('aiostreams_catalogs')->nullable()->after('manifest_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn(['manifest_url', 'aiostreams_catalogs']);
        });
    }
};
