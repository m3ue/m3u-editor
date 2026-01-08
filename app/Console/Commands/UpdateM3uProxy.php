<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class UpdateM3uProxy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'm3u-proxy:update 
                            {--force : Force update even if not using embedded proxy}
                            {--restart : Restart the proxy service after update}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the embedded m3u-proxy service to the latest version';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $usingExternalProxy = config('proxy.external_proxy_enabled', false);

        if ($usingExternalProxy && ! $this->option('force')) {
            $this->error('❌ Using external m3u-proxy service (M3U_PROXY_ENABLED=false).');
            $this->info('💡 This command updates the embedded proxy. Use --force to update anyway.');

            return self::FAILURE;
        }

        $proxyPath = '/opt/m3u-proxy';

        if (! is_dir($proxyPath)) {
            $this->error('❌ m3u-proxy directory not found at '.$proxyPath);
            $this->info('💡 This command only works inside the Docker container.');

            return self::FAILURE;
        }

        $this->info('🔄 Updating m3u-proxy...');

        // Save current commit hash for comparison
        $currentCommit = $this->getCurrentCommit($proxyPath);

        // Run git pull
        $this->info('📥 Pulling latest changes from repository...');
        $pullProcess = new Process(['git', 'pull', 'origin', 'master'], $proxyPath);
        $pullProcess->setTimeout(60);
        $pullProcess->run();

        if (! $pullProcess->isSuccessful()) {
            $this->error('❌ Failed to pull latest changes:');
            $this->error($pullProcess->getErrorOutput());

            return self::FAILURE;
        }

        $this->line($pullProcess->getOutput());

        // Check if there were updates
        $newCommit = $this->getCurrentCommit($proxyPath);

        if ($currentCommit === $newCommit) {
            $this->info('✅ m3u-proxy is already up to date!');

            return self::SUCCESS;
        }

        // Update Python dependencies
        $this->info('📦 Updating Python dependencies...');
        $pipProcess = new Process([
            '.venv/bin/pip',
            'install',
            '--no-cache-dir',
            '-r',
            'requirements.txt',
        ], $proxyPath);
        $pipProcess->setTimeout(120);
        $pipProcess->run();

        if (! $pipProcess->isSuccessful()) {
            $this->warn('⚠️  Failed to update dependencies:');
            $this->warn($pipProcess->getErrorOutput());
        }

        $this->newLine();
        $this->info('✅ m3u-proxy updated successfully!');
        $this->info('📝 Updated from commit '.substr($currentCommit, 0, 7).' to '.substr($newCommit, 0, 7));

        if ($this->option('restart')) {
            $this->info('🔄 Restarting m3u-proxy service...');
            $this->call('m3u-proxy:restart');
        } else {
            $this->newLine();
            $this->info('💡 Run "php artisan m3u-proxy:restart" to restart the service with the new version.');
        }

        return self::SUCCESS;
    }

    /**
     * Get the current git commit hash
     */
    private function getCurrentCommit(string $path): string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], $path);
        $process->run();

        return trim($process->getOutput());
    }
}
