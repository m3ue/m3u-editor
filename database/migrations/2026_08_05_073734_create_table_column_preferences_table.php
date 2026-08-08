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
        Schema::create('table_column_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The Filament-generated key identifying which table this is, e.g.
            // "{md5(ListChannels::class)}_columns" (the part of the session key
            // under the "tables." prefix) — deterministic from the page/relation
            // manager class, NOT tied to a browser session.
            $table->string('table_key');
            $table->json('value');
            $table->timestamps();

            $table->unique(['user_id', 'table_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_column_preferences');
    }
};
