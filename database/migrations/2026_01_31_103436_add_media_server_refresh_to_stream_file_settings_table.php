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
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->boolean('refresh_media_server')->default(false)->after('generate_nfo');
            $table->foreignId('media_server_integration_id')->nullable()->after('refresh_media_server')
                ->constrained('media_server_integrations')->nullOnDelete();
            $table->unsignedInteger('refresh_delay_seconds')->default(5)->after('media_server_integration_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->dropForeign(['media_server_integration_id']);
            $table->dropColumn(['refresh_media_server', 'media_server_integration_id', 'refresh_delay_seconds']);
        });
    }
};
