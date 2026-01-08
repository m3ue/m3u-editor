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
        Schema::table('epgs', function (Blueprint $table) {
            // Schedules Direct integration fields
            $table->enum('source_type', ['url', 'schedules_direct'])->after('url')->default('url');

            // Schedules Direct authentication
            $table->string('sd_username')->nullable()->after('source_type');
            $table->string('sd_password')->nullable()->after('sd_username');

            // Schedules Direct lineup configuration
            $table->string('sd_country', 3)->nullable()->after('sd_password');
            $table->string('sd_postal_code')->nullable()->after('sd_country');
            $table->string('sd_lineup_id')->nullable()->after('sd_postal_code');

            // Token and session management
            $table->string('sd_token')->nullable()->after('sd_lineup_id');
            $table->timestamp('sd_token_expires_at')->nullable()->after('sd_token');

            // Data freshness tracking
            $table->timestamp('sd_last_sync')->nullable()->after('sd_token_expires_at');
            $table->json('sd_station_ids')->nullable()->after('sd_last_sync');

            // Error tracking
            $table->json('sd_errors')->nullable()->after('sd_station_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->dropColumn([
                'source_type',
                'sd_username',
                'sd_password',
                'sd_country',
                'sd_postal_code',
                'sd_lineup_id',
                'sd_token',
                'sd_token_expires_at',
                'sd_last_sync',
                'sd_station_ids',
                'sd_errors',
            ]);
        });
    }
};
