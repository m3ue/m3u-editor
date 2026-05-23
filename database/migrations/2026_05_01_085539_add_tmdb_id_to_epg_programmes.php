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
        Schema::table('epg_programmes', function (Blueprint $table): void {
            $table->string('tmdb_id', 50)->nullable()->after('premiere');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epg_programmes', function (Blueprint $table): void {
            $table->dropColumn('tmdb_id');
        });
    }
};
