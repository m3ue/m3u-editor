<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('container_extension')->nullable()->after('is_vod');
            $table->string('year')->nullable()->after('container_extension');
            $table->string('rating')->nullable()->after('year');
            $table->double('rating_5based')->nullable()->after('rating');
        });

        // Migrate existing channels to set the container_extension based on the URL
        // This assumes that the URL ends with the file extension, e.g., "http://example.com/video.mp4"
        // If the URL does not have a file extension, it will set container_extension to null
        // This migration will process channels in chunks to avoid memory issues with large datasets
        // and will only update channels that have is_vod set to true and container_extension is null.
        // Uses the query builder (not the Channel Eloquent model) — migrations run against
        // the schema as it existed at that point in history, but an Eloquent model reflects
        // the CURRENT class definition (including any global scopes added since), which can
        // reference columns that don't exist yet during a full from-scratch migration replay
        // (e.g. tests' RefreshDatabase).
        $channels = DB::table('channels')
            ->where('is_vod', true)
            ->whereNull('container_extension');
        foreach ($channels->orderBy('id')->cursor()->chunk(100) as $chunk) {
            foreach ($chunk as $channel) {
                $containerExtension = null;
                // Extract the file extension from the URL
                if (preg_match('/\.(\w+)$/', $channel->url, $matches)) {
                    $containerExtension = strtolower($matches[1]);
                }
                // Use DB::table for direct update to avoid model events and potential issues
                DB::table('channels')
                    ->where('id', $channel->id)
                    ->update(['container_extension' => $containerExtension]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['container_extension', 'year', 'rating', 'rating_5based']);
        });
    }
};
