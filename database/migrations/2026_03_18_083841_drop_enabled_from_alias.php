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
        Schema::table('playlist_aliases', function (Blueprint $table) {
            // Find and drop the composite index by its columns, regardless of
            // naming convention (handles prefixed names from database imports)
            foreach (Schema::getIndexes('playlist_aliases') as $index) {
                if ($index['columns'] === ['playlist_id', 'enabled']) {
                    $table->dropIndex($index['name']);
                    break;
                }
            }
            $table->dropColumn('enabled');
            $table->index(['playlist_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropIndex(['playlist_id']);
            $table->boolean('enabled')->default(true);
            $table->index(['playlist_id', 'enabled']);
        });
    }
};
