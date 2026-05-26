<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->string('webdav_base_path')->nullable()->after('webdav_password');
            $table->boolean('skip_ssl_verify')->default(false)->after('webdav_base_path');
        });
    }

    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn(['webdav_base_path', 'skip_ssl_verify']);
        });
    }
};
