<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table) {
            $table->string('dvr_output_format')->default('ts')->after('use_proxy');
        });
    }

    public function down(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table) {
            $table->dropColumn('dvr_output_format');
        });
    }
};
