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
        Schema::table('merged_epg_epg', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('epg_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_epg_epg', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
