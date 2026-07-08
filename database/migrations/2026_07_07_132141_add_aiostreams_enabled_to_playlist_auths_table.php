<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->boolean('aiostreams_enabled')
                ->default(false)
                ->after('auto_approve_requests');
        });
    }

    public function down(): void
    {
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->dropColumn('aiostreams_enabled');
        });
    }
};
