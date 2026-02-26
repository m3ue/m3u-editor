<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->decimal('sort', 12, 4)->default(0)->index();
            $table->unsignedInteger('channel_number')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->dropColumn(['sort', 'channel_number']);
            $table->dropTimestamps();
        });
    }
};
