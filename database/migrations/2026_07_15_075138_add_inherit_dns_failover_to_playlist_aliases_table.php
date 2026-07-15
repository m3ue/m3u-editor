<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->boolean('inherit_dns_failover')->default(true)->after('xtream_config');
        });
    }

    public function down(): void
    {
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropColumn('inherit_dns_failover');
        });
    }
};
