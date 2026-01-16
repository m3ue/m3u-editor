<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add progress tracking and status fields to media_server_integrations table
     */
    public function up(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            // Progress tracking fields
            $table->integer('progress')->default(0)->after('sync_interval');
            $table->integer('movie_progress')->default(0)->after('progress');
            $table->integer('series_progress')->default(0)->after('movie_progress');

            // Status field (idle, processing, completed, failed)
            $table->string('status')->default('idle')->after('series_progress');

            // Total counts for progress calculation
            $table->integer('total_movies')->default(0)->after('status');
            $table->integer('total_series')->default(0)->after('total_movies');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn([
                'progress',
                'movie_progress',
                'series_progress',
                'status',
                'total_movies',
                'total_series',
            ]);
        });
    }
};
