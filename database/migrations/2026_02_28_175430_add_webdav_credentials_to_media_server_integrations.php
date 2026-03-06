<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds fields specific to WebDAV media integration:
     * - webdav_username: Username for WebDAV authentication
     * - webdav_password: Password for WebDAV authentication
     */
    public function up(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->string('webdav_username')->nullable()->after('api_key');
            $table->string('webdav_password')->nullable()->after('webdav_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn([
                'webdav_username',
                'webdav_password',
            ]);
        });
    }
};
