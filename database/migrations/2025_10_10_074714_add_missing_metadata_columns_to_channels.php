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
        // Need to make sure the column doesn't already exist (had an issue where original migration was removed but column remained)
        if (! Schema::hasColumn('channels', 'info')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->jsonb('info')->nullable()->after('logo_internal');
            });
        }
        if (! Schema::hasColumn('channels', 'movie_data')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->jsonb('movie_data')->nullable()->after('info');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('info');
            $table->dropColumn('movie_data');
        });
    }
};
