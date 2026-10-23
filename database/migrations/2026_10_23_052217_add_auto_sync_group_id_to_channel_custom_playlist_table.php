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
        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->unsignedBigInteger('auto_sync_group_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->dropColumn('auto_sync_group_id');
        });
    }
};
