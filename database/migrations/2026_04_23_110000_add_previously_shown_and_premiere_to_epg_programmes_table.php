<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epg_programmes', function (Blueprint $table) {
            $table->boolean('previously_shown')->default(false)->after('is_new');
            $table->boolean('premiere')->default(false)->after('previously_shown');
        });
    }

    public function down(): void
    {
        Schema::table('epg_programmes', function (Blueprint $table) {
            $table->dropColumn(['previously_shown', 'premiere']);
        });
    }
};
