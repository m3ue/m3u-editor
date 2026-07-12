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
        Schema::table('epg_maps', function (Blueprint $table) {
            $table->boolean('candidates_building')->default(false)->after('processing');
            $table->timestamp('candidates_built_at')->nullable()->after('candidates_building');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epg_maps', function (Blueprint $table) {
            $table->dropColumn(['candidates_building', 'candidates_built_at']);
        });
    }
};
