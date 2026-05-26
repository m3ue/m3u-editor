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
        Schema::table('dvr_settings', function (Blueprint $table) {
            $table->string('default_series_mode', 20)->default('unique_se');
            $table->unsignedSmallInteger('default_series_keep_last')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table) {
            $table->dropColumn(['default_series_mode', 'default_series_keep_last']);
        });
    }
};
