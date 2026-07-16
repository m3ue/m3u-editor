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
        Schema::table('media_requests', function (Blueprint $table) {
            $table->unique(
                ['playlist_auth_id', 'arr_integration_id', 'external_id', 'request_type'],
                'media_requests_active_unique'
            )->where("status IN ('pending', 'approved')");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_requests', function (Blueprint $table) {
            $table->dropIndex('media_requests_active_unique');
        });
    }
};
