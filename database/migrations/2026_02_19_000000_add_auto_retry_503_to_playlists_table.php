<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->unsignedTinyInteger('auto_retry_503_count')->default(0)->after('errors');
            $table->timestamp('auto_retry_503_last_at')->nullable()->after('auto_retry_503_count');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn(['auto_retry_503_count', 'auto_retry_503_last_at']);
        });
    }
};
