<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->unsignedTinyInteger('sync_retry_count')->default(0)->after('auto_retry_503_last_at');
            $table->timestamp('sync_retry_after')->nullable()->after('sync_retry_count');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn(['sync_retry_count', 'sync_retry_after']);
        });
    }
};
