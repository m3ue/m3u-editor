<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->unsignedBigInteger('sort')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }
};
