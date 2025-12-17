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
        Schema::create('strm_file_mappings', function (Blueprint $table) {
            $table->id();
            $table->morphs('syncable'); // Can be Channel (VOD) or Episode
            $table->text('sync_location'); // Base sync location
            $table->text('current_path'); // Full path to the .strm file
            $table->text('current_url')->nullable(); // URL stored in the file
            $table->json('path_options')->nullable(); // Naming options used to generate path
            $table->timestamps();

            // Index for quick lookups by syncable
            $table->index(['syncable_type', 'syncable_id', 'sync_location'], 'strm_syncable_location_idx');
            // Index for path lookups and comparisons
            $table->index('current_path', 'strm_current_path_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strm_file_mappings');
    }
};
