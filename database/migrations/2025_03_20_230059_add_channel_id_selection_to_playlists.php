<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $enumValues = [
        'stream_id',
        'channel_id',
        'name',
        'title',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->enum('id_channel_by', $this->enumValues)
                ->default('stream_id')
                ->after('streams');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->enum('id_channel_by', $this->enumValues)
                ->default('stream_id')
                ->after('enable_proxy');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->enum('id_channel_by', $this->enumValues)
                ->default('stream_id')
                ->after('enable_proxy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('id_channel_by');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('id_channel_by');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('id_channel_by');
        });
    }
};
