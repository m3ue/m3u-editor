<?php

namespace App\Console\Commands;

use App\Services\TimeshiftBufferService;
use Illuminate\Console\Command;

/**
 * Stop and delete idle shared timeshift buffers (no playlist fetch within
 * timeshift.idle_stop_seconds). Scheduled every minute from routes/console.php
 * when proxy integration + timeshift are enabled.
 */
class TimeshiftReaper extends Command
{
    protected $signature = 'app:timeshift-reaper';

    protected $description = 'Stop idle shared per-channel timeshift buffers on m3u-proxy';

    public function handle(TimeshiftBufferService $timeshift): int
    {
        if (! TimeshiftBufferService::enabled()) {
            return self::SUCCESS;
        }

        $reaped = $timeshift->reapIdle();
        if ($reaped !== []) {
            $this->info('Reaped '.count($reaped).' timeshift buffer(s): '.implode(', ', $reaped));
        }

        return self::SUCCESS;
    }
}
