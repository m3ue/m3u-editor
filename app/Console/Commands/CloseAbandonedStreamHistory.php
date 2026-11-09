<?php

namespace App\Console\Commands;

use App\Services\StreamHistoryRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:close-abandoned-stream-history')]
#[Description('End Proxy > Watch History sessions the proxy never reported closed, such as after a proxy restart.')]
class CloseAbandonedStreamHistory extends Command
{
    public function handle(StreamHistoryRecorder $recorder): int
    {
        $closed = $recorder->closeAbandoned();

        if ($closed > 0) {
            $this->info("Closed {$closed} abandoned watch history sessions.");
        }

        return self::SUCCESS;
    }
}
