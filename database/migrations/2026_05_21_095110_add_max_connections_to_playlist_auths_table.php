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
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->unsignedInteger('max_connections')->nullable()->after('expires_at');
            $table->boolean('stop_oldest_on_limit')->nullable()->after('max_connections');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->dropColumn(['max_connections', 'stop_oldest_on_limit']);
        });
    }
};
