<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add library selection fields to media_server_integrations table.
     * This allows users to select specific libraries (Movies/TV Shows) to import
     * instead of importing all content from the media server.
     */
    public function up(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            // Available libraries discovered from the media server
            // Format: [{"id": "lib1", "name": "Movies", "type": "movies", "item_count": 150}, ...]
            $table->jsonb('available_libraries')->nullable()->after('import_series');

            // Selected library IDs that should be imported
            // Format: ["lib1", "lib2", ...]
            $table->jsonb('selected_library_ids')->nullable()->after('available_libraries');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn(['available_libraries', 'selected_library_ids']);
        });
    }
};
