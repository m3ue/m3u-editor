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
            if (! Schema::hasColumn('media_server_integrations', 'auto_sync')) {
                $table->boolean('auto_sync')->default(true)->after('import_series');
            }
            if (! Schema::hasColumn('media_server_integrations', 'sync_interval')) {
                $table->string('sync_interval')->default('0 */6 * * *')->after('auto_sync'); // Default: every 6 hours
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn(['auto_sync', 'sync_interval']);
        });
    }
};
