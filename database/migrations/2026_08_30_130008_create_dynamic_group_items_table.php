<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polymorphic membership rows for DynamicGroup → Channel|Series.
     *
     * The dynamic_group_id column is a real FK so ON DELETE CASCADE keeps
     * membership clean when a DynamicGroup row is removed (e.g. user
     * removes the rule from the per-playlist config).
     *
     * item_type / item_id use the standard Laravel morphs() helper, which
     * creates an indexed (item_type, item_id) lookup path for the Xtream
     * stream-filter subqueries and stores FQCN strings (matching the
     * existing taggables.taggable_type convention in the codebase).
     */
    public function up(): void
    {
        Schema::create('dynamic_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dynamic_group_id')->constrained()->cascadeOnDelete();
            $table->morphs('item'); // adds indexed (item_type, item_id)
            $table->unique(['dynamic_group_id', 'item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_group_items');
    }
};
