<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User-defined, reusable selections of a playlist's groups (issue #1391).
     *
     * A bouquet targets exactly one of playlist_id / custom_playlist_id (enforced
     * at the application layer — SQLite cannot ALTER in a CHECK). Membership is
     * stored as provider-stable NAME arrays in group_selections, mirroring
     * playlist_aliases.group_filter, so selections survive the source_groups
     * hard-delete / groups soft-delete churn and union cleanly with manual filters.
     *
     * Both Postgres and SQLite treat NULLs as distinct in unique indexes, so each
     * of the two composite uniques constrains only rows of its own target type.
     */
    public function up(): void
    {
        Schema::create('bouquets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('custom_playlist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('group_selections')->nullable();
            $table->boolean('auto_include_new_live')->default(false);
            $table->boolean('auto_include_new_vod')->default(false);
            $table->timestamps();

            $table->unique(['playlist_id', 'name']);
            $table->unique(['custom_playlist_id', 'name']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bouquets');
    }
};
