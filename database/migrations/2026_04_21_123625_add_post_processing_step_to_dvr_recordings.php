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
            $table->string('post_processing_step')->nullable()->after('error_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dvr_recordings', function (Blueprint $table) {
            $table->dropColumn('post_processing_step');
        });
    }
};
