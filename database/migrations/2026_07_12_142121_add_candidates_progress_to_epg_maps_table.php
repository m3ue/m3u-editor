<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epg_maps', function (Blueprint $table) {
            $table->float('candidates_progress')->default(0)->after('candidates_built_at');
        });
    }

    public function down(): void
    {
        Schema::table('epg_maps', function (Blueprint $table) {
            $table->dropColumn('candidates_progress');
        });
    }
};
