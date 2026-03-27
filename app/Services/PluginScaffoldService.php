<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ZipArchive;

/**
 * Generates plugin scaffold files from stubs.
 *
 * Shared by the make:plugin artisan command and the Create Plugin UI wizard.
 * Returns in-memory file trees or writes them to disk/zip — never touches
 * the database or plugin registry.
 */
class PluginScaffoldService
{
    /**
     * Build the full scaffold file tree in memory.
     *
     * @param  string  $name  Human-friendly name or slug
     * @param  string  $description  Plugin description for the manifest
     * @param  array<int, string>  $capabilities  Capability ids to declare
     * @param  array<int, string>  $hooks  Hook names to subscribe to
     * @param  string  $cleanupMode  Default cleanup mode (preserve|purge)
     * @param  bool  $lifecycle  Include lifecycle uninstall hook stub
     * @param  bool  $bare  Only generate plugin.json and Plugin.php
     * @return array<string, string> Relative path => file content
     *
     * @throws InvalidArgumentException On unknown capabilities, hooks, or cleanup mode
     */
    public function scaffold(
        string $name,
        string $description = '',
        array $capabilities = [],
        array $hooks = [],
        string $cleanupMode = 'preserve',
        bool $lifecycle = false,
        bool $bare = false,
    ): array {
        $ids = $this->deriveIdentifiers($name);

        if ($ids['pluginId'] === '') {
            throw new InvalidArgumentException('The plugin name could not be converted into a valid plugin id.');
        }

        $this->validateCapabilities($capabilities);
        $this->validateHooks($hooks);
        $this->validateCleanupMode($cleanupMode);

        $description = $description ?: "Generated plugin scaffold for {$ids['displayName']}.";

        $files = [];

        // plugin.json — always generated
        $manifest = $this->buildManifest(
            pluginId: $ids['pluginId'],
            displayName: $ids['displayName'],
            classSegment: $ids['classSegment'],
            description: $description,
            capabilities: $capabilities,
            hooks: $hooks,
            cleanupMode: $cleanupMode,
        );
        $files['plugin.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

        // Plugin.php — always generated
        $files['Plugin.php'] = $this->renderClassStub(
            classSegment: $ids['classSegment'],
            pluginId: $ids['pluginId'],
            displayName: $ids['displayName'],
            capabilities: $capabilities,
            hooks: $hooks,
            withLifecycleHook: $lifecycle,
        );

        // Starter kit files — skipped in bare mode
        if (! $bare) {
            $files = array_merge($files, $this->buildStarterKitFiles(
                pluginId: $ids['pluginId'],
                displayName: $ids['displayName'],
                classSegment: $ids['classSegment'],
                capabilities: $capabilities,
                hooks: $hooks,
            ));
        }

        return $files;
    }

    /**
     * Scaffold and write files to a directory on disk.
     *
     * @return string The absolute path to the plugin directory
     *
     * @throws InvalidArgumentException On validation failure or if directory exists without force
     */
    public function scaffoldToDirectory(
        string $targetDirectory,
        string $name,
        string $description = '',
        array $capabilities = [],
        array $hooks = [],
        string $cleanupMode = 'preserve',
        bool $lifecycle = false,
        bool $bare = false,
        bool $force = false,
    ): string {
        $pluginId = Str::slug(trim($name));
        $pluginPath = rtrim($targetDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$pluginId;

        if (File::exists($pluginPath)) {
            if (! $force) {
                throw new InvalidArgumentException("Plugin directory [{$pluginId}] already exists. Use force to replace it.");
            }
            File::deleteDirectory($pluginPath);
        }

        $files = $this->scaffold($name, $description, $capabilities, $hooks, $cleanupMode, $lifecycle, $bare);

        File::ensureDirectoryExists($pluginPath);

        foreach ($files as $relativePath => $content) {
            $filePath = $pluginPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            File::ensureDirectoryExists(dirname($filePath));
            File::put($filePath, $content);

            if (str_starts_with($relativePath, 'scripts/')) {
                @chmod($filePath, 0755);
            }
        }

        return $pluginPath;
    }

    /**
     * Scaffold and package as a ZIP file.
     *
     * @return string Path to the temporary zip file (caller must clean up)
     */
    public function scaffoldToZip(
        string $name,
        string $description = '',
        array $capabilities = [],
        array $hooks = [],
        string $cleanupMode = 'preserve',
        bool $lifecycle = false,
        bool $bare = false,
    ): string {
        $pluginId = Str::slug(trim($name));
        $files = $this->scaffold($name, $description, $capabilities, $hooks, $cleanupMode, $lifecycle, $bare);

        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$pluginId.'-'.Str::random(8).'.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create zip file at [{$zipPath}].");
        }

        foreach ($files as $relativePath => $content) {
            // Nest everything under the plugin id directory inside the zip
            $zip->addFromString($pluginId.'/'.$relativePath, $content);
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * Get the plugin id that would be generated from a name.
     */
    public function derivePluginId(string $name): string
    {
        return Str::slug(trim($name));
    }

    /**
     * Derive all naming variants from a human-friendly plugin name.
     *
     * @return array{pluginId: string, displayName: string, classSegment: string}
     */
    private function deriveIdentifiers(string $name): array
    {
        $name = trim($name);
        $pluginId = Str::slug($name);

        $displayName = Str::of($name)
            ->replace(['-', '_'], ' ')
            ->squish()
            ->title()
            ->value();

        $classSegment = Str::studly(
            Str::of($name)->replace(['-', '_'], ' ')->squish()->value()
        );

        return compact('pluginId', 'displayName', 'classSegment');
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validateCapabilities(array $capabilities): void
    {
        $known = array_keys(config('plugins.capabilities', []));
        $unknown = array_values(array_diff($capabilities, $known));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown capability(s): '.implode(', ', $unknown).'. Known: '.implode(', ', $known)
            );
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validateHooks(array $hooks): void
    {
        $known = array_keys(config('plugins.hooks', []));
        $unknown = array_values(array_diff($hooks, $known));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown hook(s): '.implode(', ', $unknown).'. Known: '.implode(', ', $known)
            );
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validateCleanupMode(string $cleanupMode): void
    {
        $supported = config('plugins.cleanup_modes', []);

        if (! in_array($cleanupMode, $supported, true)) {
            throw new InvalidArgumentException(
                "Unsupported cleanup mode [{$cleanupMode}]. Supported: ".implode(', ', $supported)
            );
        }
    }

    /**
     * Build the plugin.json manifest array.
     */
    private function buildManifest(
        string $pluginId,
        string $displayName,
        string $classSegment,
        string $description,
        array $capabilities,
        array $hooks,
        string $cleanupMode,
    ): array {
        $settings = [];

        if (in_array('scheduled', $capabilities, true)) {
            $settings[] = [
                'id' => 'schedule_enabled',
                'label' => 'Enable Scheduled Health Checks',
                'type' => 'boolean',
                'default' => false,
            ];
            $settings[] = [
                'id' => 'schedule_cron',
                'label' => 'Schedule Cron',
                'type' => 'text',
                'default' => '0 * * * *',
            ];
        }

        $permissions = ['queue_jobs', 'filesystem_write'];
        if ($hooks !== []) {
            $permissions[] = 'hook_subscriptions';
        }
        if (in_array('scheduled', $capabilities, true)) {
            $permissions[] = 'scheduled_runs';
        }

        return [
            'id' => $pluginId,
            'name' => $displayName,
            'version' => '1.0.0',
            'api_version' => (string) config('plugins.api_version'),
            'description' => $description,
            'entrypoint' => 'Plugin.php',
            'class' => "AppLocalPlugins\\{$classSegment}\\Plugin",
            'capabilities' => $capabilities,
            'hooks' => $hooks,
            'permissions' => array_values(array_unique($permissions)),
            'schema' => [
                'tables' => [],
            ],
            'data_ownership' => [
                'tables' => [],
                'directories' => [
                    "plugin-data/{$pluginId}",
                    "plugin-reports/{$pluginId}",
                ],
                'files' => [],
                'default_cleanup_policy' => $cleanupMode,
            ],
            'settings' => $settings,
            'actions' => [[
                'id' => 'health_check',
                'label' => 'Health Check',
                'dry_run' => true,
                'fields' => [],
            ]],
        ];
    }

    /**
     * Render the Plugin.php class from the stub template.
     */
    private function renderClassStub(
        string $classSegment,
        string $pluginId,
        string $displayName,
        array $capabilities,
        array $hooks,
        bool $withLifecycleHook,
    ): string {
        $namespace = "AppLocalPlugins\\{$classSegment}";
        // Extract the interface class from each capability's config (now nested arrays)
        $capabilitiesConfig = config('plugins.capabilities', []);
        $interfaceClasses = ['App\\Plugins\\Contracts\\PluginInterface'];

        foreach ($capabilities as $capability) {
            $interfaceClasses[] = $capabilitiesConfig[$capability]['interface'];
        }

        if ($hooks !== []) {
            $interfaceClasses[] = 'App\\Plugins\\Contracts\\HookablePluginInterface';
        }

        if ($withLifecycleHook) {
            $interfaceClasses[] = 'App\\Plugins\\Contracts\\LifecyclePluginInterface';
        }

        $interfaceClasses = array_values(array_unique($interfaceClasses));

        $useClasses = array_merge(
            [
                'App\\Plugins\\Support\\PluginActionResult',
                'App\\Plugins\\Support\\PluginExecutionContext',
            ],
            $interfaceClasses,
        );

        $scheduledCapabilityInterface = $capabilitiesConfig['scheduled']['interface'] ?? null;
        $hasScheduledCapability = $scheduledCapabilityInterface !== null
            && in_array($scheduledCapabilityInterface, $interfaceClasses, true);

        if ($hasScheduledCapability) {
            $useClasses[] = 'Carbon\\CarbonInterface';
            $useClasses[] = 'Cron\\CronExpression';
        }

        if ($withLifecycleHook) {
            $useClasses[] = 'App\\Plugins\\Support\\PluginUninstallContext';
        }

        $useClasses = array_values(array_unique($useClasses));
        sort($useClasses);

        $uses = implode(PHP_EOL, array_map(
            fn (string $class) => 'use '.$class.';',
            $useClasses,
        ));

        $implements = implode(', ', array_map(
            fn (string $class) => class_basename($class),
            $interfaceClasses,
        ));

        $replacements = [
            '{{ namespace }}' => $namespace,
            '{{ uses }}' => $uses,
            '{{ class }}' => 'Plugin',
            '{{ implements }}' => $implements,
            '{{ plugin_id }}' => $pluginId,
            '{{ display_name }}' => $displayName,
            '{{ hook_method }}' => $hooks !== [] ? $this->hookMethodStub() : '',
            '{{ scheduled_method }}' => $hasScheduledCapability ? $this->scheduledMethodStub() : '',
            '{{ uninstall_method }}' => $withLifecycleHook ? $this->uninstallMethodStub() : '',
        ];

        return strtr(File::get(base_path('stubs/plugins/plugin.class.stub')), $replacements);
    }

    /**
     * Build starter kit files (everything except plugin.json and Plugin.php).
     *
     * @return array<string, string> Relative path => content
     */
    private function buildStarterKitFiles(
        string $pluginId,
        string $displayName,
        string $classSegment,
        array $capabilities,
        array $hooks,
    ): array {
        $replacements = [
            '{{ plugin_id }}' => $pluginId,
            '{{ display_name }}' => $displayName,
            '{{ class_segment }}' => $classSegment,
            '{{ namespace }}' => "AppLocalPlugins\\{$classSegment}",
            '{{ capabilities_list }}' => $this->bulletList($capabilities, 'No capabilities declared yet.'),
            '{{ hooks_list }}' => $this->bulletList($hooks, 'No hooks declared yet.'),
        ];

        // Skill references go into both .agents/ and .claude/ for cross-tool compatibility
        $skillPaths = ['.agents/skills/plugin-dev', '.claude/skills/plugin-dev'];

        $stubMap = [
            'README.md' => 'stubs/plugins/README.stub',
            'AGENTS.md' => 'stubs/plugins/AGENTS.stub',
            'CLAUDE.md' => 'stubs/plugins/CLAUDE.stub',
            '.github/workflows/plugin-ci.yml' => 'stubs/plugins/plugin-ci.stub',
            'scripts/package-plugin.sh' => 'stubs/plugins/package-plugin.stub',
            'scripts/validate-plugin.php' => 'stubs/plugins/validate-plugin.stub',
        ];

        foreach ($skillPaths as $skillDir) {
            $stubMap["{$skillDir}/SKILL.md"] = 'stubs/plugins/plugin-dev-skill.stub';
            $stubMap["{$skillDir}/references/manifest.md"] = 'stubs/plugins/skill-ref-manifest.stub';
            $stubMap["{$skillDir}/references/hooks-and-capabilities.md"] = 'stubs/plugins/skill-ref-hooks.stub';
            $stubMap["{$skillDir}/references/models.md"] = 'stubs/plugins/skill-ref-models.stub';
        }

        $files = [];
        foreach ($stubMap as $relativePath => $stubPath) {
            $files[$relativePath] = strtr(File::get(base_path($stubPath)), $replacements);
        }

        return $files;
    }

    private function renderTemplate(string $stubPath, array $replacements): string
    {
        return strtr(File::get(base_path($stubPath)), $replacements);
    }

    private function bulletList(array $values, string $emptyMessage): string
    {
        if ($values === []) {
            return '- '.$emptyMessage;
        }

        return implode(PHP_EOL, array_map(
            fn (string $value) => '- '.$value,
            $values,
        ));
    }

    private function hookMethodStub(): string
    {
        return File::get(base_path('stubs/plugins/hook-method.stub'));
    }

    private function scheduledMethodStub(): string
    {
        return File::get(base_path('stubs/plugins/scheduled-method.stub'));
    }

    private function uninstallMethodStub(): string
    {
        return File::get(base_path('stubs/plugins/uninstall-method.stub'));
    }
}
