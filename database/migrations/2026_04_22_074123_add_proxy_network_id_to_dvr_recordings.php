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
        Schema::table('dvr_recordings', function (Blueprint $table) {
            // Tracks the proxy broadcast ID (= recording UUID) so the editor can
            // stop the proxy process and redirect live viewers to the HLS stream.
            $table->string('proxy_network_id')->nullable()->after('uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dvr_recordings', function (Blueprint $table) {
            $table->dropColumn('proxy_network_id');
        });
    }
};
