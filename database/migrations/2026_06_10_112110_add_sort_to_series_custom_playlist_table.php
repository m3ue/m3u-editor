<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series_custom_playlist', function (Blueprint $table) {
            $table->float('sort')->nullable()->after('custom_playlist_id');
        });
    }

    public function down(): void
    {
        Schema::table('series_custom_playlist', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }
};
