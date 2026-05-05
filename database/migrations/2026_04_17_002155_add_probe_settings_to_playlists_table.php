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
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('probe_use_batching')->default(false)->after('auto_probe_streams');
            $table->unsignedSmallInteger('probe_timeout')->default(15)->after('probe_use_batching');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn(['probe_use_batching', 'probe_timeout']);
        });
    }
};
