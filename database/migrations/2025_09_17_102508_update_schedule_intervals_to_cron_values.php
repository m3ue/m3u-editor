<?php

use App\Models\Epg;
use App\Models\Playlist;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Create mapping of old interval values to new cron expressions
    private $mapping = [
        '15 minutes' => '*/15 * * * *',
        '30 minutes' => '*/30 * * * *',
        '45 minutes' => '*/45 * * * *',
        '1 hour' => '0 * * * *',
        '2 hours' => '0 */2 * * *',
        '3 hours' => '0 */3 * * *',
        '4 hours' => '0 */4 * * *',
        '5 hours' => '0 */5 * * *',
        '6 hours' => '0 */6 * * *',
        '7 hours' => '0 */7 * * *',
        '8 hours' => '0 */8 * * *',
        '12 hours' => '0 */12 * * *',
        '24 hours' => '0 0 * * *',
        '2 days' => '0 0 */2 * *',
        '3 days' => '0 0 */3 * *',
        '1 week' => '0 0 * * 0',
        '2 weeks' => '0 0 * * 0,7',
        '1 month' => '0 0 1 * *',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->mapping as $old => $new) {
            // Update playlists
            Playlist::where('sync_interval', $old)
                ->update(['sync_interval' => $new]);

            // Update EPGs
            Epg::where('sync_interval', $old)
                ->update(['sync_interval' => $new]);
        }
    }
};
