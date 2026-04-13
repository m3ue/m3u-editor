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
        Schema::table('stream_profiles', function (Blueprint $table) {
            // 'ffmpeg' (default), 'streamlink', or 'ytdlp'
            $table->string('backend')->default('ffmpeg')->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stream_profiles', function (Blueprint $table) {
            $table->dropColumn('backend');
        });
    }
};
