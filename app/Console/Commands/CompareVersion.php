<?php

namespace App\Console\Commands;

use App\Providers\VersionServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CompareVersion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compare the installed version with the latest version.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Check for updates
        $installedVersion = VersionServiceProvider::getVersion();
        $remoteVersion = VersionServiceProvider::getRemoteVersion(refresh: true);
        $updateAvailable = VersionServiceProvider::updateAvailable();

        $this->info("Installed version: $installedVersion");
        $this->info("Latest version: $remoteVersion");

        // Output results
        if ($updateAvailable) {
            $this->info("🔥 Update available! Latest version: $remoteVersion, installed version: $installedVersion\n");
        } else {
            $this->info("✅ No updates available.\n");
        }
        return 1;
    }
}
