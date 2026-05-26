<?php

namespace App\Console\Commands;

use App\Services\PluginScaffoldService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MakePlugin extends Command
{
    protected $signature = 'make:plugin
        {name : Human-friendly plugin name or slug}
        {--description= : Plugin description written into plugin.json}
        {--capability=* : Capability ids to declare}
        {--hook=* : Hook names to subscribe to}
        {--table=* : Table base names to scaffold with a UI (e.g. profiles)}
        {--cleanup=preserve : Default cleanup mode for uninstall (preserve|purge)}
        {--lifecycle : Include a lifecycle uninstall hook stub}
        {--bare : Generate only plugin.json and Plugin.php without the starter kit}
        {--force : Overwrite the target plugin directory if it already exists}';

    protected $description = 'Scaffold a trusted-local plugin with a valid manifest and Plugin.php entrypoint.';

    public function handle(PluginScaffoldService $scaffoldService): int
    {
        $name = trim((string) $this->argument('name'));
        $pluginId = $scaffoldService->derivePluginId($name);
        $pluginRoot = collect(config('plugins.directories', [base_path('plugins')]))->first() ?: base_path('plugins');

        try {
            $pluginPath = $scaffoldService->scaffoldToDirectory(
                targetDirectory: $pluginRoot,
                name: $name,
                description: (string) ($this->option('description') ?: ''),
                capabilities: $this->normalizeListOption($this->option('capability')),
                hooks: $this->normalizeListOption($this->option('hook')),
                cleanupMode: (string) $this->option('cleanup'),
                lifecycle: (bool) $this->option('lifecycle'),
                bare: (bool) $this->option('bare'),
                force: (bool) $this->option('force'),
                tables: $this->normalizeListOption($this->option('table')),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $relativePath = Str::startsWith($pluginPath, base_path().DIRECTORY_SEPARATOR)
            ? Str::after($pluginPath, base_path().DIRECTORY_SEPARATOR)
            : $pluginPath;

        $this->info("Created plugin [{$pluginId}] in [{$relativePath}].");
        $this->line('Next steps:');
        $this->line("  php artisan plugins:stage-directory {$relativePath}");
        $this->line('  php artisan plugins:scan-install <review-id>');
        $this->line('  php artisan plugins:approve-install <review-id> --trust');
        $this->line('  php artisan plugins:discover');
        if (! $this->option('bare')) {
            $this->line("  bash {$relativePath}/scripts/package-plugin.sh");
            $this->line('  Publish the packaged zip with its SHA-256 checksum for reviewed GitHub installs.');
        }

        return self::SUCCESS;
    }

    /**
     * Parse CLI list options that may be comma-separated or repeated flags.
     */
    private function normalizeListOption(array|string|null $values): array
    {
        $items = is_array($values) ? $values : [$values];

        return collect($items)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->flatMap(fn (string $value) => array_map('trim', explode(',', $value)))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }
}
