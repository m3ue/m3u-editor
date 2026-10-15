<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Radarr movies dynamic-group auto-cache added to an integration with
     * "Remove after leaving dynamic groups" (`cache_cleanup`) on, so
     * `cache:cleanup` can remove them once they've been out of every group
     * for the keep days. `left_at` is set while a movie is out of every
     * group.
     */
    public function up(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->boolean('cache_cleanup')->default(false)->after('cache_enabled');
        });

        Schema::create('arr_cache_movies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arr_integration_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('tmdb_id');
            $table->unsignedInteger('arr_movie_id');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['arr_integration_id', 'tmdb_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arr_cache_movies');

        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->dropColumn('cache_cleanup');
        });
    }
};
