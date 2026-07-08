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
        Schema::create('aiostreams_watch_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained('media_server_integrations')->cascadeOnDelete();
            $table->string('item_id');       // IMDb/TMDB ID e.g. "tt1234567"
            $table->string('item_type', 20); // "movie" | "series"
            $table->integer('position_seconds')->default(0);
            $table->integer('duration_seconds')->nullable();
            $table->boolean('completed')->default(false);
            $table->string('name');
            $table->string('poster_url', 1024)->nullable();
            $table->timestamp('last_watched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'integration_id', 'item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aiostreams_watch_progress');
    }
};
