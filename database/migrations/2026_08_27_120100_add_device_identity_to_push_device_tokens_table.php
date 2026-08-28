<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a mobile push registration to its `tv_devices` registry row (same
     * client-generated `device_id`) and carries the human-readable device name
     * so the Devices tab can show one row per physical device. Both nullable so
     * older app builds that don't send them keep working unchanged.
     */
    public function up(): void
    {
        Schema::table('push_device_tokens', function (Blueprint $table) {
            $table->string('device_id')->nullable()->after('token');
            $table->string('device_name')->nullable()->after('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('push_device_tokens', function (Blueprint $table) {
            $table->dropColumn(['device_id', 'device_name']);
        });
    }
};
