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
        Schema::table('device_authorizations', function (Blueprint $table) {
            // Every credential granted to the device, in the order the admin
            // set (first = primary). playlist_auth_id keeps the primary for
            // pairings made before this column existed.
            $table->json('playlist_auth_ids')->nullable()->after('playlist_auth_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_authorizations', function (Blueprint $table) {
            $table->dropColumn('playlist_auth_ids');
        });
    }
};
