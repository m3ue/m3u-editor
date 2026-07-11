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
        Schema::table('network_content', function (Blueprint $table) {
            $table->string('pin_day_of_week')->nullable()->after('weight');
            $table->string('pin_time_of_day')->nullable()->after('pin_day_of_week');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('network_content', function (Blueprint $table) {
            $table->dropColumn(['pin_day_of_week', 'pin_time_of_day']);
        });
    }
};
