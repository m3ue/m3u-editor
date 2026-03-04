<?php

namespace App\Console\Commands;

use App\Http\Controllers\LogoProxyController;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;

class LogoCacheCleanup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:logo-cleanup 
                                {--force : Force cleanup without confirmation}
                                {--all : Clear all, not just expired}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up cached logo files';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $all = $this->option('all') ?? false;

        if (! $all && $this->isPermanentCacheEnabled()) {
            $this->info('Skipping expired logo cache cleanup because permanent cache is enabled.');

            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('This will delete expired logo cache files. Continue?')) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        $this->info('Cleaning up expired logo cache...');

        $controller = new LogoProxyController;
        $clearedCount = $all
            ? $controller->clearCache()
            : $controller->clearExpiredCache();

        $this->info("Cleared {$clearedCount} logo cache files.");

        return Command::SUCCESS;
    }

    protected function isPermanentCacheEnabled(): bool
    {
        try {
            $settings = app(GeneralSettings::class);

            return (bool) ($settings->logo_cache_permanent ?? false);
        } catch (\Exception $e) {
            return false;
        }
    }
}
