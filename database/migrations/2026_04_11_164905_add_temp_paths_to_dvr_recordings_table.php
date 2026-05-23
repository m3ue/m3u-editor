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
            $table->string('temp_path')->nullable()->after('pid');
            $table->string('temp_manifest_path')->nullable()->after('temp_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dvr_recordings', function (Blueprint $table) {
            $table->dropColumn(['temp_path', 'temp_manifest_path']);
        });
    }
};
