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
            $table->float('sd_progress')
                ->default(0)
                ->after('sd_station_ids');
            $table->unsignedBigInteger('sd_days_to_import')
                ->default(3)
                ->after('sd_progress');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->dropColumn(['sd_progress', 'sd_days_to_import']);
        });
    }
};
