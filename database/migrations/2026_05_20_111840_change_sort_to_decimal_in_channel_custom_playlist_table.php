<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->dropIndex(['sort']);
            $table->decimal('sort', 10, 4)->nullable()->change();
            $table->index('sort');
        });
    }

    public function down(): void
    {
        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->dropIndex(['sort']);
            $table->unsignedBigInteger('sort')->nullable()->change();
            $table->index('sort');
        });
    }
};
