<?php

use App\Models\Channel;
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
        // Make sure the column does not already exist
        if (! Schema::hasColumn('channels', 'source_id')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->string('source_id')->nullable()->after('stream_id');
            });
        }

        // Update existing channels to set source_id to their stream_id
        // The `url` variable will contain the stream ID in the last path, minus the extension
        // E.g., "https://example.com/stream/12345.m3u8" will set source_id to "12345"
        // This assumes that the URL is well-formed and contains a stream ID at the end

        // Process channels in smaller batches to avoid memory issues
        Channel::whereNotNull('url')
            ->chunkById(100, function ($channels) {
                foreach ($channels as $channel) {
                    $urlParts = explode('/', $channel->url);
                    $streamIdWithExtension = end($urlParts);
                    $streamId = pathinfo($streamIdWithExtension, PATHINFO_FILENAME);

                    // Use DB::table for direct update to avoid model events and potential issues
                    DB::table('channels')
                        ->where('id', $channel->id)
                        ->update(['source_id' => $streamId]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('source_id');
        });
    }
};
