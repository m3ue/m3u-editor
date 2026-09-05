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
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->unsignedBigInteger('emby_managed_setup_binding_id')->nullable();
            $table->string('emby_managed_setup_root', 1024)->nullable();
            $table->unsignedSmallInteger('emby_managed_setup_capability_version')->nullable();
            $table->unsignedSmallInteger('emby_managed_setup_contract_version')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn([
                'emby_managed_setup_binding_id',
                'emby_managed_setup_root',
                'emby_managed_setup_capability_version',
                'emby_managed_setup_contract_version',
            ]);
        });
    }
};
