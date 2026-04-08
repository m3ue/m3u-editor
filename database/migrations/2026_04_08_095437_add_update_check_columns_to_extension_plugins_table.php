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
        Schema::table('extension_plugins', function (Blueprint $table) {
            $table->string('repository')->nullable()->after('source_type');
            $table->string('latest_version')->nullable()->after('version');
            $table->text('latest_release_url')->nullable()->after('latest_version');
            $table->string('latest_release_sha256', 64)->nullable()->after('latest_release_url');
            $table->json('latest_release_metadata')->nullable()->after('latest_release_sha256');
            $table->boolean('update_check_enabled')->default(true)->after('latest_release_metadata');
            $table->timestamp('last_update_check_at')->nullable()->after('update_check_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('extension_plugins', function (Blueprint $table) {
            $table->dropColumn([
                'repository',
                'latest_version',
                'latest_release_url',
                'latest_release_sha256',
                'latest_release_metadata',
                'update_check_enabled',
                'last_update_check_at',
            ]);
        });
    }
};
