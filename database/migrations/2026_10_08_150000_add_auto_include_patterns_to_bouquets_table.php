<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional regex filters for the auto-include toggles. When set, a newly
     * appearing provider group is only appended to the bouquet if its name
     * matches one of the patterns; null/empty keeps the existing "add every new
     * group" behavior. Nullable column adds are metadata-only on Postgres.
     */
    public function up(): void
    {
        Schema::table('bouquets', function (Blueprint $table) {
            $table->jsonb('auto_include_live_patterns')->nullable()->after('auto_include_new_live');
            $table->jsonb('auto_include_vod_patterns')->nullable()->after('auto_include_new_vod');
        });
    }

    public function down(): void
    {
        Schema::table('bouquets', function (Blueprint $table) {
            $table->dropColumn(['auto_include_live_patterns', 'auto_include_vod_patterns']);
        });
    }
};
