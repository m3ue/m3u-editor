<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->string('movie_format')->nullable()->default(null)->change();
            $table->string('episode_format')->nullable()->default(null)->change();
        });

        DB::table('stream_file_settings')->update([
            'movie_format' => null,
            'episode_format' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->string('movie_format')->default('{title} ({year}){edition}{quality}{audio}{video}{-group}')->change();
            $table->string('episode_format')->default('{title} - S{season}E{episode}{-title}{quality}{audio}{video}{-group}')->change();
        });
    }
};
