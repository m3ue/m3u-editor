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
        Schema::table('viewer_watch_progress', function (Blueprint $table) {
            $table->unsignedSmallInteger('episode_number')->nullable()->after('season_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('viewer_watch_progress', function (Blueprint $table) {
            $table->dropColumn('episode_number');
        });
    }
};
