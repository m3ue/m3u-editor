<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds auto_fetch_metadata field to enable automatic TMDB lookup
     * after local media sync completes.
     */
    public function up(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->boolean('auto_fetch_metadata')->default(true)->after('metadata_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn('auto_fetch_metadata');
        });
    }
};
