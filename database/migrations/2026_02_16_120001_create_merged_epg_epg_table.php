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
        Schema::disableForeignKeyConstraints();

        Schema::create('merged_epg_epg', function (Blueprint $table) {
            $table->foreignId('merged_epg_id')->constrained('epgs')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('epg_id')->constrained('epgs')->cascadeOnDelete()->cascadeOnUpdate();

            $table->unique(['merged_epg_id', 'epg_id']);
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merged_epg_epg');
    }
};
