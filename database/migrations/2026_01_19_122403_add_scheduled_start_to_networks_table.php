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
        Schema::table('networks', function (Blueprint $table) {
            $table->timestamp('broadcast_scheduled_start')->nullable()->after('broadcast_initial_offset_seconds');
            $table->boolean('broadcast_schedule_enabled')->default(false)->after('broadcast_scheduled_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn(['broadcast_scheduled_start', 'broadcast_schedule_enabled']);
        });
    }
};
