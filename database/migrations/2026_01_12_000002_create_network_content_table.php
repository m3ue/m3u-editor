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
        Schema::create('network_content', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->morphs('contentable'); // Can be Episode or Channel (VOD movie)
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('weight')->default(1); // For shuffle mode weighting
            $table->timestamps();

            // Ensure a content item can only be added once per network
            $table->unique(['network_id', 'contentable_type', 'contentable_id'], 'network_content_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('network_content');
    }
};
