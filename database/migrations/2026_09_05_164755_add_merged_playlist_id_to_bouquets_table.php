<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Third bouquet target: a merged playlist (issue #1391, building on #1483).
     *
     * A merged-target bouquet stores its selections as {playlist_id, name} pairs
     * (the same shape a merged-playlist alias's group_filter uses) so a group can
     * be allowed from one source without also allowing a same-named group from
     * another. The "exactly one target" rule stays application-enforced. Like the
     * other two composite uniques, NULLs-distinct means this one only constrains
     * merged-target rows.
     */
    public function up(): void
    {
        Schema::table('bouquets', function (Blueprint $table) {
            $table->foreignId('merged_playlist_id')->nullable()->after('custom_playlist_id')
                ->constrained()->cascadeOnDelete();
            $table->unique(['merged_playlist_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('bouquets', function (Blueprint $table) {
            $table->dropUnique(['merged_playlist_id', 'name']);
            $table->dropConstrainedForeignId('merged_playlist_id');
        });
    }
};
