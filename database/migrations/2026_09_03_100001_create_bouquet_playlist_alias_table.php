<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alias <-> bouquet assignment (many-to-many, union semantics). No sort
     * column: assignment order is meaningless under union composition. The
     * playlist_alias_id index serves the hot request-time direction
     * (alias -> bouquets); the unique covers bouquet -> aliases for the UI.
     */
    public function up(): void
    {
        Schema::create('bouquet_playlist_alias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bouquet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_alias_id')->constrained()->cascadeOnDelete();

            $table->unique(['bouquet_id', 'playlist_alias_id']);
            $table->index('playlist_alias_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bouquet_playlist_alias');
    }
};
