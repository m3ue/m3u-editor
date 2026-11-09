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
        Schema::create('viewer_sports_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_viewer_id')->constrained('playlist_viewers')->cascadeOnDelete();
            // "{sport}/{league}:{team id}", e.g. "hockey/nhl:21" - stable across ESPN refreshes.
            $table->string('team_key', 120);
            $table->string('league_id', 80);
            $table->string('team_id', 40);
            $table->string('name');
            $table->timestamp('followed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['playlist_viewer_id', 'team_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('viewer_sports_follows');
    }
};
