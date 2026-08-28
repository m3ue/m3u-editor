<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add "merged group" support: a group (or category) flagged is_merged acts as a
     * pass-through parent whose name/sort stands in for its children in playlist output.
     * Children point at it via parent_id. Resolution is entirely read-time - nothing
     * is written back to channels, so re-imports never need to reconcile anything.
     */
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('is_merged')->default(false)->after('custom');
            $table->foreignId('parent_id')->nullable()->after('is_merged')
                ->constrained('groups')->nullOnDelete();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_merged')->default(false)->after('name_internal');
            $table->foreignId('parent_id')->nullable()->after('is_merged')
                ->constrained('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('is_merged');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('is_merged');
        });
    }
};
