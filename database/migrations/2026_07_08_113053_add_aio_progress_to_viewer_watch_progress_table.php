<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viewer_watch_progress', function (Blueprint $table) {
            // Allow NULL for AIO content (which has no integer stream_id).
            $table->unsignedBigInteger('stream_id')->nullable()->change();

            // AIO-specific identity fields.
            $table->string('aio_item_id', 64)->nullable()->after('stream_id');
            $table->unsignedInteger('aio_integration_id')->nullable()->after('aio_item_id');

            // Denormalised metadata for AIO items (no Channel/Episode joins available).
            $table->string('title', 512)->nullable()->after('watch_count');
            $table->text('thumbnail_url')->nullable()->after('title');
            $table->text('backdrop_url')->nullable()->after('thumbnail_url');
            $table->string('rating', 20)->nullable()->after('backdrop_url');
            $table->string('year', 10)->nullable()->after('rating');
            $table->text('plot')->nullable()->after('year');

            // Unique index for AIO items: MySQL ignores NULL in unique indexes so
            // non-AIO rows (aio_item_id = NULL) are never in conflict here.
            $table->unique(['playlist_viewer_id', 'aio_item_id'], 'vwp_viewer_aio_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('viewer_watch_progress', function (Blueprint $table) {
            $table->dropUnique('vwp_viewer_aio_item_unique');
            $table->dropColumn([
                'aio_item_id',
                'aio_integration_id',
                'title',
                'thumbnail_url',
                'backdrop_url',
                'rating',
                'year',
                'plot',
            ]);
            $table->unsignedBigInteger('stream_id')->nullable(false)->change();
        });
    }
};
