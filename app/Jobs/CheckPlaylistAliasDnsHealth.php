<?php

namespace App\Jobs;

use App\Enums\DnsFailoverMode;
use App\Models\PlaylistAlias;
use App\Services\XtreamHealthService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckPlaylistAliasDnsHealth implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public PlaylistAlias $playlistAlias
    ) {}

    public function uniqueId(): string
    {
        return "alias_dns_{$this->playlistAlias->id}";
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(2);
    }

    public function handle(): void
    {
        if ($this->playlistAlias->dns_failover_mode !== DnsFailoverMode::Independent) {
            return;
        }

        XtreamHealthService::resolveWorkingAliasUrls($this->playlistAlias);
    }
}
