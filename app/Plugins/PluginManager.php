<?php

namespace App\Plugins;

use App\Jobs\ExecutePluginInvocation;
use App\Models\Plugin;
use App\Models\PluginInstallReview;
use App\Models\PluginRun;
use App\Models\User;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\LifecyclePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Plugins\Support\PluginUninstallContext;
use App\Plugins\Support\PluginValidationResult;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PharData;
use RuntimeException;
use Throwable;
use ZipArchive;

class PluginManager
{
    public function __construct(
        private readonly PluginValidator $validator,
        private readonly PluginSchemaMapper $schemaMapper,
        private readonly PluginManifestLoader $manifestLoader,
        private readonly PluginIntegrityService $integrityService,
        private readonly PluginSchemaManager $schemaManager,
        private readonly PluginMalwareScanner $malwareScanner,
    ) {}

    public function discover(): array
    {
        $discovered = [];
        $seenPaths = [];

        foreach ($this->pluginPaths() as $pluginPath) {
            $result = $this->validator->validatePath($pluginPath);
            $manifest = $result->manifest;
            $pluginId = $result->pluginId ?? basename($pluginPath);

            $record = Plugin::query()->where('plugin_id', $pluginId)->first()
                ?? new Plugin(['plugin_id' => $pluginId]);
            $securityState = $this->determineSecurityState($record, $result, file_exists($pluginPath));
            $attributes = [
                'name' => $manifest?->name ?? Arr::get($result->manifestData, 'name', $pluginId),
                'version' => $manifest?->version,
                'api_version' => $manifest?->apiVersion ?? Arr::get($result->manifestData, 'api_version'),
                'description' => $manifest?->description ?? Arr::get($result->manifestData, 'description'),
                'entrypoint' => $manifest?->entrypoint ?? Arr::get($result->manifestData, 'entrypoint'),
                'class_name' => $manifest?->className ?? Arr::get($result->manifestData, 'class'),
                'capabilities' => $manifest?->capabilities ?? Arr::get($result->manifestData, 'capabilities', []),
                'hooks' => $manifest?->hooks ?? Arr::get($result->manifestData, 'hooks', []),
                'actions' => $manifest?->actions ?? Arr::get($result->manifestData, 'actions', []),
                'permissions' => $manifest?->permissions ?? Arr::get($result->manifestData, 'permissions', []),
                'schema_definition' => $manifest?->schema ?? Arr::get($result->manifestData, 'schema', []),
                'settings_schema' => $manifest?->settings ?? Arr::get($result->manifestData, 'settings', []),
                'data_ownership' => $manifest?->dataOwnership ?? Arr::get($result->manifestData, 'data_ownership', []),
                'repository' => $manifest?->repository ?? $record->repository,
                'path' => $pluginPath,
                'source_type' => $this->determineSourceType($pluginPath, $record),
                'available' => true,
                'validation_status' => $result->valid ? 'valid' : 'invalid',
                'validation_errors' => $result->errors,
                'manifest_hash' => $result->hashes['manifest_hash'] ?? null,
                'entrypoint_hash' => $result->hashes['entrypoint_hash'] ?? null,
                'plugin_hash' => $result->hashes['plugin_hash'] ?? null,
                'trust_state' => $securityState['trust_state'],
                'trust_reason' => $securityState['trust_reason'],
                'integrity_status' => $securityState['integrity_status'],
                'integrity_verified_at' => $securityState['integrity_verified_at'],
                'enabled' => $securityState['enabled'],
                'last_discovered_at' => now(),
                'last_validated_at' => now(),
            ];

            $record = Plugin::query()->updateOrCreate(
                ['plugin_id' => $pluginId],
                $attributes,
            );

            $seenPaths[] = $pluginPath;
            $discovered[] = $record->fresh();
        }

        if ($seenPaths !== []) {
            $missingPlugins = Plugin::query()
                ->whereNotIn('path', $seenPaths)
                ->get();

            foreach ($missingPlugins as $missingPlugin) {
                $trustState = $missingPlugin->isBlocked() ? 'blocked' : 'pending_review';
                $missingPlugin->update([
                    'available' => false,
                    'enabled' => false,
                    'integrity_status' => 'missing',
                    'trust_state' => $trustState,
                    'trust_reason' => 'Plugin files are missing from disk and require operator review.',
                ]);
            }
        }

        return $discovered;
    }

    public function validate(Plugin $plugin): Plugin
    {
        $result = $this->validator->validatePath((string) $plugin->path);
        $securityState = $this->determineSecurityState($plugin, $result, file_exists((string) $plugin->path));

        $plugin->update([
            'name' => $result->manifest?->name ?? $plugin->name,
            'version' => $result->manifest?->version ?? $plugin->version,
            'api_version' => $result->manifest?->apiVersion ?? $plugin->api_version,
            'description' => $result->manifest?->description ?? $plugin->description,
            'entrypoint' => $result->manifest?->entrypoint ?? $plugin->entrypoint,
            'class_name' => $result->manifest?->className ?? $plugin->class_name,
            'capabilities' => $result->manifest?->capabilities ?? $plugin->capabilities,
            'hooks' => $result->manifest?->hooks ?? $plugin->hooks,
            'actions' => $result->manifest?->actions ?? $plugin->actions,
            'permissions' => $result->manifest?->permissions ?? $plugin->permissions,
            'schema_definition' => $result->manifest?->schema ?? $plugin->schema_definition,
            'settings_schema' => $result->manifest?->settings ?? $plugin->settings_schema,
            'data_ownership' => $result->manifest?->dataOwnership ?? $plugin->data_ownership,
            'repository' => $result->manifest?->repository ?? $plugin->repository,
            'validation_status' => $result->valid ? 'valid' : 'invalid',
            'validation_errors' => $result->errors,
            'manifest_hash' => $result->hashes['manifest_hash'] ?? null,
            'entrypoint_hash' => $result->hashes['entrypoint_hash'] ?? null,
            'plugin_hash' => $result->hashes['plugin_hash'] ?? null,
            'trust_state' => $securityState['trust_state'],
            'trust_reason' => $securityState['trust_reason'],
            'integrity_status' => $securityState['integrity_status'],
            'integrity_verified_at' => $securityState['integrity_verified_at'],
            'enabled' => $securityState['enabled'],
            'last_validated_at' => now(),
            'available' => file_exists((string) $plugin->path),
        ]);

        return $plugin->fresh();
    }

    public function findPluginById(string $pluginId): ?Plugin
    {
        return Plugin::query()
            ->where('plugin_id', $pluginId)
            ->first();
    }

    public function findInstallReviewById(int $reviewId): ?PluginInstallReview
    {
        return PluginInstallReview::query()->find($reviewId);
    }

    public function stageDirectoryReview(string $sourcePath, ?int $userId = null, bool $devSource = false): PluginInstallReview
    {
        $sourcePath = $this->normalizeRealPath($sourcePath);
        if (! is_dir($sourcePath)) {
            throw new RuntimeException("Plugin source directory [{$sourcePath}] does not exist.");
        }

        if (! file_exists($sourcePath.DIRECTORY_SEPARATOR.'plugin.json')) {
            throw new RuntimeException("Plugin source directory [{$sourcePath}] must contain plugin.json.");
        }

        if ($devSource) {
            $this->assertDevSourceAllowed($sourcePath);
        }

        $review = PluginInstallReview::query()->create([
            'source_type' => $devSource ? 'local_dev' : 'local_directory',
            'source_path' => $sourcePath,
            'status' => 'staged',
            'validation_status' => 'pending',
            'scan_status' => 'pending',
            'created_by_user_id' => $userId,
        ]);

        $stagingRoot = $this->reviewStagingRoot($review);
        try {
            $this->resetReviewStagingRoot($stagingRoot);
            $workingPath = $stagingRoot.DIRECTORY_SEPARATOR.'source';

            File::ensureDirectoryExists($workingPath);
            if (! File::copyDirectory($sourcePath, $workingPath)) {
                throw new RuntimeException("Unable to copy plugin source directory [{$sourcePath}] into review staging.");
            }

            return $this->refreshInstallReview(
                $review->fresh(),
                sourcePath: $sourcePath,
                stagingPath: $stagingRoot,
                extractedPath: $workingPath,
            );
        } catch (Throwable $exception) {
            $this->cleanupFailedReviewStage($review, $stagingRoot);

            throw $exception;
        }
    }

    public function stageArchiveReview(string $archivePath, ?int $userId = null): PluginInstallReview
    {
        $archivePath = $this->normalizeRealPath($archivePath);
        if (! is_file($archivePath)) {
            throw new RuntimeException("Plugin archive [{$archivePath}] does not exist.");
        }

        $this->assertArchiveSizeWithinLimit($archivePath, $archivePath);

        $review = PluginInstallReview::query()->create([
            'source_type' => 'staged_archive',
            'source_path' => $archivePath,
            'archive_filename' => basename($archivePath),
            'status' => 'staged',
            'validation_status' => 'pending',
            'scan_status' => 'pending',
            'created_by_user_id' => $userId,
        ]);

        $stagingRoot = $this->reviewStagingRoot($review);
        try {
            $this->resetReviewStagingRoot($stagingRoot);

            $stagedArchivePath = $stagingRoot.DIRECTORY_SEPARATOR.basename($archivePath);
            File::copy($archivePath, $stagedArchivePath);
            $this->assertArchiveSizeWithinLimit($stagedArchivePath, $archivePath);
            $review->update([
                'archive_sha256' => hash_file('sha256', $stagedArchivePath) ?: null,
            ]);

            $extractRoot = $stagingRoot.DIRECTORY_SEPARATOR.'extracted';
            File::ensureDirectoryExists($extractRoot);
            $pluginPath = $this->extractPluginArchive($stagedArchivePath, $extractRoot);

            return $this->refreshInstallReview(
                $review->fresh(),
                sourcePath: $archivePath,
                stagingPath: $stagingRoot,
                extractedPath: $pluginPath,
                archivePath: $stagedArchivePath,
                archiveFilename: basename($archivePath),
            );
        } catch (Throwable $exception) {
            $this->cleanupFailedReviewStage($review, $stagingRoot);

            throw $exception;
        }
    }

    public function stageUploadedArchiveReview(string $uploadedPath, ?int $userId = null): PluginInstallReview
    {
        $uploadedPath = trim($uploadedPath);
        if ($uploadedPath === '' || ! Storage::disk('local')->exists($uploadedPath)) {
            throw new RuntimeException('Uploaded plugin archive is missing from local storage.');
        }

        $uploadDirectory = trim((string) config('plugins.upload_directory', 'plugin-review-uploads'), '/');
        $absoluteUploadRoot = Storage::disk('local')->path($uploadDirectory);
        File::ensureDirectoryExists($absoluteUploadRoot);

        $absoluteUploadedPath = realpath(Storage::disk('local')->path($uploadedPath));
        $resolvedUploadRoot = realpath($absoluteUploadRoot);

        if ($absoluteUploadedPath === false || ! is_file($absoluteUploadedPath)) {
            throw new RuntimeException('Uploaded plugin archive is missing from local storage.');
        }

        if ($resolvedUploadRoot === false) {
            throw new RuntimeException('Configured plugin upload directory could not be resolved on local storage.');
        }

        $this->assertAbsolutePathWithinRoot(
            $absoluteUploadedPath,
            $resolvedUploadRoot,
            'Uploaded plugin archives must come from the configured plugin upload directory.',
        );

        $archiveFilename = basename($uploadedPath);
        $sourceReference = 'browser-upload://'.$archiveFilename;
        $review = null;
        $stagingRoot = null;

        try {
            $this->assertArchiveSizeWithinLimit($absoluteUploadedPath, $archiveFilename);

            $review = PluginInstallReview::query()->create([
                'source_type' => 'uploaded_archive',
                'source_path' => $sourceReference,
                'source_origin' => 'browser_upload',
                'source_metadata' => [
                    'disk' => 'local',
                    'upload_path' => $uploadedPath,
                    'uploaded_filename' => $archiveFilename,
                ],
                'archive_filename' => $archiveFilename,
                'status' => 'staged',
                'validation_status' => 'pending',
                'scan_status' => 'pending',
                'created_by_user_id' => $userId,
            ]);

            $stagingRoot = $this->reviewStagingRoot($review);
            $this->resetReviewStagingRoot($stagingRoot);

            $stagedArchivePath = $stagingRoot.DIRECTORY_SEPARATOR.$archiveFilename;
            if (! File::move($absoluteUploadedPath, $stagedArchivePath)) {
                throw new RuntimeException("Unable to move uploaded plugin archive [{$archiveFilename}] into review staging.");
            }

            $archiveSha256 = hash_file('sha256', $stagedArchivePath) ?: null;

            $review->update([
                'archive_path' => $stagedArchivePath,
                'archive_sha256' => $archiveSha256,
            ]);

            $extractRoot = $stagingRoot.DIRECTORY_SEPARATOR.'extracted';
            File::ensureDirectoryExists($extractRoot);
            $pluginPath = $this->extractPluginArchive($stagedArchivePath, $extractRoot);

            return $this->refreshInstallReview(
                $review->fresh(),
                sourcePath: $sourceReference,
                stagingPath: $stagingRoot,
                extractedPath: $pluginPath,
                archivePath: $stagedArchivePath,
                archiveFilename: $archiveFilename,
            );
        } catch (Throwable $exception) {
            $this->cleanupFailedReviewStage(
                $review,
                $stagingRoot,
                cleanup: function () use ($absoluteUploadedPath, $archiveFilename): void {
                    if (is_file($absoluteUploadedPath)) {
                        $this->deleteFileOrFail($absoluteUploadedPath, "uploaded plugin archive [{$archiveFilename}]");
                    }
                },
            );

            throw $exception;
        }
    }

    public function stageGithubReleaseReview(string $releaseUrl, string $expectedSha256, ?int $userId = null): PluginInstallReview
    {
        $metadata = $this->parseGithubReleaseUrl($releaseUrl);
        $expectedSha256 = $this->normalizeSha256($expectedSha256, 'Expected GitHub release SHA-256');

        $review = PluginInstallReview::query()->create([
            'source_type' => 'github_release',
            'source_path' => $releaseUrl,
            'source_origin' => "{$metadata['repository']}@{$metadata['tag']}",
            'source_metadata' => $metadata,
            'archive_filename' => $metadata['asset_name'],
            'expected_archive_sha256' => $expectedSha256,
            'status' => 'staged',
            'validation_status' => 'pending',
            'scan_status' => 'pending',
            'created_by_user_id' => $userId,
        ]);

        $stagingRoot = $this->reviewStagingRoot($review);
        try {
            $this->resetReviewStagingRoot($stagingRoot);

            $stagedArchivePath = $stagingRoot.DIRECTORY_SEPARATOR.$metadata['asset_name'];
            $this->downloadGithubReleaseArchive($releaseUrl, $stagedArchivePath);
            $this->assertArchiveSizeWithinLimit($stagedArchivePath, $releaseUrl);

            $archiveSha256 = hash_file('sha256', $stagedArchivePath) ?: null;
            if (! $archiveSha256 || ! hash_equals($expectedSha256, $archiveSha256)) {
                throw new RuntimeException("GitHub release checksum mismatch for [{$metadata['asset_name']}].");
            }

            $review->update([
                'archive_path' => $stagedArchivePath,
                'archive_sha256' => $archiveSha256,
            ]);

            $extractRoot = $stagingRoot.DIRECTORY_SEPARATOR.'extracted';
            File::ensureDirectoryExists($extractRoot);
            $pluginPath = $this->extractPluginArchive($stagedArchivePath, $extractRoot);

            return $this->refreshInstallReview(
                $review->fresh(),
                sourcePath: $releaseUrl,
                stagingPath: $stagingRoot,
                extractedPath: $pluginPath,
                archivePath: $stagedArchivePath,
                archiveFilename: $metadata['asset_name'],
            );
        } catch (Throwable $exception) {
            $this->cleanupFailedReviewStage($review, $stagingRoot);

            throw $exception;
        }
    }

    public function scanInstallReview(PluginInstallReview $review): PluginInstallReview
    {
        $review = $this->ensureReviewPayloadAvailable($review);

        $scan = $this->malwareScanner->scan(
            $review->extracted_path,
            $review->archive_path,
        );

        $status = $review->validation_status === 'valid' ? 'review_ready' : 'staged';
        if (($scan['status'] ?? null) === 'infected') {
            $status = 'rejected';
        }

        $review->update([
            'scan_status' => $scan['status'] ?? 'scan_failed',
            'scan_summary' => $scan['summary'] ?? 'Plugin scan failed.',
            'scan_details' => $scan['details'] ?? [],
            'scanned_at' => now(),
            'status' => $status,
        ]);

        return $review->fresh();
    }

    public function approveInstallReview(PluginInstallReview $review, bool $trust = false, ?int $userId = null, ?string $notes = null): PluginInstallReview
    {
        $review = $this->ensureReviewPayloadAvailable($review);
        $review = $this->refreshInstallReview(
            $review->fresh(),
            sourcePath: $review->source_path,
            stagingPath: $review->staging_path,
            extractedPath: $review->extracted_path,
            archivePath: $review->archive_path,
            archiveFilename: $review->archive_filename,
        );

        if ($review->validation_status !== 'valid') {
            throw new RuntimeException('Only valid plugin reviews can be approved for install.');
        }

        if ($this->scanRequiredForReview($review) && $review->scan_status !== 'clean') {
            throw new RuntimeException('A clean ClamAV scan is required before this install review can be approved for trust.');
        }

        if (! $review->plugin_id || ! $review->extracted_path) {
            throw new RuntimeException('Install review is missing plugin metadata or extracted content.');
        }

        $targetPath = $this->managedPluginDirectory().DIRECTORY_SEPARATOR.$review->plugin_id;
        $existingPlugin = $this->findPluginById($review->plugin_id);
        $usesExistingManagedDirectory = $review->source_type === 'local_directory'
            && $this->normalizeRealPath((string) $review->source_path) === $this->normalizeRealPath($targetPath);
        $replacingExistingPlugin = is_dir($targetPath) && ! $usesExistingManagedDirectory;
        $existingPluginWasEnabled = (bool) $existingPlugin?->enabled;

        if ($existingPlugin?->hasActiveRuns()) {
            throw new RuntimeException("Plugin [{$review->plugin_id}] has active runs and cannot be replaced right now.");
        }

        if (! $usesExistingManagedDirectory) {
            File::ensureDirectoryExists(dirname($targetPath));
            $incomingPath = $targetPath.'.incoming-'.Str::random(6);
            $backupPath = $targetPath.'.backup-'.Str::random(6);

            if (is_dir($incomingPath)) {
                File::deleteDirectory($incomingPath);
            }

            if (! File::copyDirectory($review->extracted_path, $incomingPath)) {
                throw new RuntimeException("Failed to copy reviewed plugin files into the incoming staging area for [{$targetPath}].");
            }

            if ($replacingExistingPlugin) {
                if (! File::copyDirectory($targetPath, $backupPath)) {
                    File::deleteDirectory($incomingPath);
                    throw new RuntimeException("Failed to back up the existing plugin directory [{$targetPath}] before update.");
                }

                if (! File::deleteDirectory($targetPath)) {
                    File::deleteDirectory($incomingPath);
                    File::deleteDirectory($backupPath);
                    throw new RuntimeException("Failed to remove the existing plugin directory [{$targetPath}] before update.");
                }
            }

            if (! File::copyDirectory($incomingPath, $targetPath)) {
                File::deleteDirectory($targetPath);

                if (is_dir($backupPath)) {
                    File::copyDirectory($backupPath, $targetPath);
                }

                File::deleteDirectory($incomingPath);

                throw new RuntimeException("Failed to install reviewed plugin files into [{$targetPath}].");
            }

            File::deleteDirectory($incomingPath);

            if (is_dir($backupPath)) {
                File::deleteDirectory($backupPath);
            }
        }

        $plugin = collect($this->discover())->firstWhere('plugin_id', $review->plugin_id)
            ?? throw new RuntimeException("Installed plugin [{$review->plugin_id}] could not be discovered after install.");

        $plugin->update([
            'source_type' => $review->source_type,
            'installation_status' => 'installed',
            'available' => true,
            'repository' => $plugin->repository ?? $this->repositoryFromReview($review),
        ]);
        $plugin = $this->validate($plugin->fresh());

        $review->update([
            'status' => $trust ? 'approved' : 'installed',
            'review_notes' => $notes,
            'approved_at' => now(),
            'approved_by_user_id' => $userId,
            'installed_at' => now(),
            'installed_path' => $targetPath,
            'extension_plugin_id' => $plugin->id,
        ]);

        if ($trust) {
            $plugin = $this->trust($plugin->fresh(), $userId, $notes);

            if ($replacingExistingPlugin && $existingPluginWasEnabled) {
                $plugin->update(['enabled' => true]);
                $plugin = $plugin->fresh();
            }

            $review->update([
                'status' => 'installed',
                'extension_plugin_id' => $plugin->id,
            ]);
        }

        if ($review->source_type === 'uploaded_archive') {
            $this->cleanupInstalledUploadedReview($review->fresh());
        }

        return $review->fresh();
    }

    public function rejectInstallReview(PluginInstallReview $review, ?int $userId = null, ?string $notes = null): PluginInstallReview
    {
        $review->update([
            'status' => 'rejected',
            'review_notes' => $notes,
            'rejected_at' => now(),
            'rejected_by_user_id' => $userId,
        ]);

        return $review->fresh();
    }

    public function discardInstallReview(PluginInstallReview $review): void
    {
        if ($review->status === 'installed') {
            throw new RuntimeException('Installed reviews cannot be discarded. Reject or uninstall the plugin instead.');
        }

        if ($review->staging_path && is_dir($review->staging_path)) {
            $this->deleteDirectoryOrFail($review->staging_path, "plugin review staging directory [{$review->staging_path}]");
        }

        $review->update([
            'status' => 'discarded',
            'archive_path' => null,
            'staging_path' => null,
            'extracted_path' => null,
        ]);
    }

    public function resolvedSettings(Plugin $plugin): array
    {
        return $this->schemaMapper->defaultsForFields(
            $plugin->settings_schema ?? [],
            $plugin->settings ?? [],
        );
    }

    public function updateSettings(Plugin $plugin, array $settings): Plugin
    {
        Validator::make(
            ['settings' => $settings],
            $this->schemaMapper->settingsRules($plugin),
        )->validate();
        $this->assertOwnedModelSelections(
            $plugin->settings_schema ?? [],
            $settings,
            auth()->user(),
        );

        $plugin->update([
            'settings' => $settings + $this->resolvedSettings($plugin),
        ]);

        return $plugin->fresh();
    }

    /**
     * Stage a plugin update from its stored latest release metadata.
     */
    public function stageUpdateFromLatestRelease(Plugin $plugin, ?int $userId = null): PluginInstallReview
    {
        if (! $plugin->hasUpdateAvailable()) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] does not have an update available.");
        }

        if (! $plugin->latest_release_url) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] has no release download URL stored.");
        }

        $sha256 = $plugin->latest_release_sha256;
        if (! $sha256) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] has no verified SHA-256 checksum for the latest release. Stage the update manually and provide the checksum.");
        }

        return $this->stageGithubReleaseReview($plugin->latest_release_url, $sha256, $userId);
    }

    /**
     * Derive a repository shorthand from a GitHub release install review.
     */
    private function repositoryFromReview(PluginInstallReview $review): ?string
    {
        if ($review->source_type !== 'github_release') {
            return null;
        }

        $metadata = $review->source_metadata;
        if (! is_array($metadata) || ! isset($metadata['owner'], $metadata['repo'])) {
            return null;
        }

        return $metadata['owner'].'/'.$metadata['repo'];
    }

    public function executeAction(
        Plugin $plugin,
        string $action,
        array $payload = [],
        array $options = [],
    ): PluginRun {
        $this->recoverStaleRuns();
        $actingUser = isset($options['user_id']) ? User::find($options['user_id']) : null;

        $run = $this->prepareRun($plugin, [
            'trigger' => $options['trigger'] ?? 'manual',
            'invocation_type' => 'action',
            'action' => $action,
            'payload' => $payload,
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'user_id' => $options['user_id'] ?? null,
        ], $options);

        try {
            Validator::make($payload, $this->schemaMapper->actionRules($plugin, $action))->validate();
            $this->assertOwnedModelSelections(
                $plugin->getActionDefinition($action)['fields'] ?? [],
                $payload,
                $actingUser,
            );

            $instance = $this->instantiate($plugin);
            $context = new PluginExecutionContext(
                plugin: $plugin,
                run: $run,
                trigger: (string) ($options['trigger'] ?? 'manual'),
                dryRun: (bool) ($options['dry_run'] ?? false),
                hook: null,
                user: $actingUser,
                settings: $this->resolvedSettings($plugin),
            );

            $result = $instance->runAction($action, $payload, $context);

            return $this->finishRun($run, $result);
        } catch (Throwable $exception) {
            return $this->failRun($run, $exception->getMessage());
        }
    }

    public function executeHook(
        Plugin $plugin,
        string $hook,
        array $payload = [],
        array $options = [],
    ): PluginRun {
        $this->recoverStaleRuns();

        $run = $this->prepareRun($plugin, [
            'trigger' => $options['trigger'] ?? 'hook',
            'invocation_type' => 'hook',
            'hook' => $hook,
            'payload' => $payload,
            'dry_run' => (bool) ($options['dry_run'] ?? true),
            'user_id' => $options['user_id'] ?? null,
        ], $options);

        try {
            $instance = $this->instantiate($plugin);
            if (! $instance instanceof HookablePluginInterface) {
                throw new RuntimeException("Plugin [{$plugin->plugin_id}] does not implement hook handling.");
            }

            $context = new PluginExecutionContext(
                plugin: $plugin,
                run: $run,
                trigger: (string) ($options['trigger'] ?? 'hook'),
                dryRun: (bool) ($options['dry_run'] ?? true),
                hook: $hook,
                user: isset($options['user_id']) ? User::find($options['user_id']) : null,
                settings: $this->resolvedSettings($plugin),
            );

            $result = $instance->runHook($hook, $payload, $context);

            return $this->finishRun($run, $result);
        } catch (Throwable $exception) {
            return $this->failRun($run, $exception->getMessage());
        }
    }

    public function scheduledInvocations(Plugin $plugin, CarbonInterface $now): array
    {
        $instance = $this->instantiate($plugin);
        if (! $instance instanceof ScheduledPluginInterface) {
            return [];
        }

        return $instance->scheduledActions($now, $this->resolvedSettings($plugin));
    }

    public function enabledPluginsForHook(string $hook)
    {
        return Plugin::query()
            ->where('enabled', true)
            ->where('available', true)
            ->where('installation_status', 'installed')
            ->where('validation_status', 'valid')
            ->where('trust_state', 'trusted')
            ->where('integrity_status', 'verified')
            ->get()
            ->filter(fn (Plugin $plugin) => in_array($hook, $plugin->hooks ?? [], true))
            ->values();
    }

    public function instantiate(Plugin $plugin): PluginInterface
    {
        $plugin = $this->validate($plugin);
        $this->assertPluginLoadable($plugin, requireEnabled: false);
        $this->assertPluginRunnable($plugin);

        $entrypoint = rtrim((string) $plugin->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$plugin->entrypoint;
        if (! class_exists($plugin->class_name, false)) {
            require_once $entrypoint;
        }

        $instance = app($plugin->class_name);
        if (! $instance instanceof PluginInterface) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] did not resolve to a valid plugin instance.");
        }

        return $instance;
    }

    public function trust(Plugin $plugin, ?int $userId = null, ?string $reason = null): Plugin
    {
        $plugin = $this->validate($plugin);
        $this->assertPluginLoadable($plugin, requireEnabled: false);

        if ($plugin->validation_status !== 'valid') {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] must validate successfully before it can be trusted.");
        }

        if (! $plugin->manifest_hash || ! $plugin->entrypoint_hash || ! $plugin->plugin_hash) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is missing integrity hashes and cannot be trusted.");
        }

        $review = $this->approvedReviewForPlugin($plugin);
        if (! $this->trustWithoutReviewAllowed($plugin) && ! $review) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] needs an approved install review before it can be trusted.");
        }

        if ($review && $this->scanRequiredForReview($review) && $review->scan_status !== 'clean') {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] cannot be trusted until its install review has a clean ClamAV result.");
        }

        $schema = $plugin->schema_definition ?? [];
        if (($schema['tables'] ?? []) !== []) {
            $this->schemaManager->apply($schema);
        }

        $plugin->update([
            'trust_state' => 'trusted',
            'trust_reason' => $reason ?: 'Plugin reviewed and trusted by an administrator.',
            'trusted_at' => now(),
            'trusted_by_user_id' => $userId,
            'blocked_at' => null,
            'blocked_by_user_id' => null,
            'integrity_status' => 'verified',
            'integrity_verified_at' => now(),
            'trusted_hashes' => $this->currentHashSnapshot($plugin),
        ]);

        return $plugin->fresh();
    }

    public function block(Plugin $plugin, ?string $reason = null, ?int $userId = null): Plugin
    {
        $plugin->update([
            'enabled' => false,
            'trust_state' => 'blocked',
            'trust_reason' => $reason ?: 'Plugin blocked by an administrator.',
            'blocked_at' => now(),
            'blocked_by_user_id' => $userId,
        ]);

        return $plugin->fresh();
    }

    public function verifyIntegrity(Plugin $plugin): Plugin
    {
        return $this->validate($plugin);
    }

    public function uninstall(Plugin $plugin, string $cleanupMode = 'preserve', ?int $userId = null): Plugin
    {
        if (! in_array($cleanupMode, config('plugins.cleanup_modes', []), true)) {
            throw new RuntimeException("Unsupported cleanup mode [{$cleanupMode}]");
        }

        $activeRuns = $plugin->runs()
            ->where('status', 'running')
            ->get();

        foreach ($activeRuns as $run) {
            $this->requestCancellation($run, $userId);
        }

        if ($cleanupMode === 'purge' && $activeRuns->isNotEmpty()) {
            throw new RuntimeException('Active runs were asked to stop. Wait for them to finish, then retry uninstall with data purge.');
        }

        $ownership = $this->ownershipForPlugin($plugin);

        if ($cleanupMode === 'purge') {
            $this->runPluginUninstallHook($plugin, $cleanupMode, $ownership, $userId);
            $this->purgeOwnedData($ownership);
        }

        $plugin->update([
            'enabled' => false,
            'installation_status' => 'uninstalled',
            'last_cleanup_mode' => $cleanupMode,
            'uninstalled_at' => now(),
            'data_ownership' => $ownership,
        ]);

        return $plugin->fresh();
    }

    public function forgetRegistryRecord(Plugin $plugin): void
    {
        if ($plugin->hasActiveRuns()) {
            throw new RuntimeException('Cannot forget a plugin registry record while it still has active runs.');
        }

        $plugin->delete();
    }

    public function deleteFromDisk(Plugin $plugin, string $cleanupMode = 'preserve', ?int $userId = null): void
    {
        if ($plugin->isBundled()) {
            throw new RuntimeException("Bundled plugin [{$plugin->plugin_id}] cannot be deleted from the UI. Remove it from the bundled plugins directory manually.");
        }

        if ($plugin->hasActiveRuns()) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] has active runs and cannot be deleted right now. Wait for them to finish or cancel them first.");
        }

        if ($plugin->isInstalled()) {
            $this->uninstall($plugin, $cleanupMode, $userId);
            $plugin = $plugin->fresh();
        }

        if ($plugin->path && is_dir((string) $plugin->path)) {
            File::deleteDirectory((string) $plugin->path);
        }

        // Remove all install reviews for this plugin, cleaning up any staging directories.
        PluginInstallReview::query()
            ->where('plugin_id', $plugin->plugin_id)
            ->each(function (PluginInstallReview $review): void {
                if ($review->staging_path && is_dir($review->staging_path)) {
                    File::deleteDirectory($review->staging_path);
                }
                $review->delete();
            });

        $plugin->delete();
    }

    public function reinstall(Plugin $plugin): Plugin
    {
        $plugin->update([
            'installation_status' => 'installed',
            'last_cleanup_mode' => null,
            'uninstalled_at' => null,
        ]);
        $plugin = $this->validate($plugin->fresh());

        if ($plugin->isTrusted() && $plugin->hasVerifiedIntegrity() && ($plugin->schema_definition['tables'] ?? []) !== []) {
            $this->schemaManager->apply($plugin->schema_definition ?? []);
        }

        return $plugin->fresh();
    }

    public function requestCancellation(PluginRun $run, ?int $userId = null): PluginRun
    {
        if ($run->status !== 'running') {
            return $run->fresh();
        }

        $run->logs()->create([
            'level' => 'warning',
            'message' => 'Cancellation requested by operator.',
            'context' => [
                'user_id' => $userId,
            ],
        ]);

        $run->update([
            'cancel_requested' => true,
            'cancel_requested_at' => now(),
            'last_heartbeat_at' => now(),
            'progress_message' => 'Cancellation requested. Waiting for the worker to stop cleanly.',
        ]);

        return $run->fresh();
    }

    public function resumeRun(PluginRun $run, ?int $userId = null): PluginRun
    {
        $plugin = $run->plugin()->firstOrFail();

        if (! in_array($run->status, ['cancelled', 'stale', 'failed'], true)) {
            return $run->fresh();
        }

        dispatch(new ExecutePluginInvocation(
            pluginId: $plugin->id,
            invocationType: $run->invocation_type,
            name: $run->action ?? $run->hook ?? throw new RuntimeException('Run cannot be resumed without an action or hook name.'),
            payload: $run->payload ?? [],
            options: [
                'trigger' => $run->trigger,
                'dry_run' => $run->dry_run,
                'user_id' => $userId ?? $run->user_id,
                'existing_run_id' => $run->id,
                'resume' => true,
            ],
        ));

        return $run->fresh();
    }

    public function recoverStaleRuns(int $minutes = 15): int
    {
        $staleRuns = PluginRun::query()
            ->where('status', 'running')
            ->where(function ($query) use ($minutes) {
                $query
                    ->where(function ($heartbeatQuery) use ($minutes) {
                        $heartbeatQuery
                            ->whereNotNull('last_heartbeat_at')
                            ->where('last_heartbeat_at', '<', now()->subMinutes($minutes));
                    })
                    ->orWhere(function ($legacyQuery) use ($minutes) {
                        $legacyQuery
                            ->whereNull('last_heartbeat_at')
                            ->whereNotNull('started_at')
                            ->where('started_at', '<', now()->subMinutes($minutes));
                    });
            })
            ->get();

        foreach ($staleRuns as $run) {
            $summary = $run->progress_message ?: $run->summary ?: 'Run lost its heartbeat and was marked stale.';

            $run->logs()->create([
                'level' => 'warning',
                'message' => 'Run heartbeat expired. Marking the run as stale so an operator can resume or rerun it.',
                'context' => [
                    'last_heartbeat_at' => optional($run->last_heartbeat_at)->toDateTimeString(),
                ],
            ]);

            $run->update([
                'status' => 'stale',
                'summary' => $summary,
                'stale_at' => now(),
                'finished_at' => $run->finished_at ?? now(),
                'result' => [
                    'status' => 'stale',
                    'success' => false,
                    'summary' => $summary,
                    'data' => [
                        'run_state' => $run->run_state ?? [],
                    ],
                ],
            ]);
        }

        return $staleRuns->count();
    }

    private function pluginPaths(): array
    {
        $paths = [];
        $directories = array_merge(
            config('plugins.bundled_directories', []),
            config('plugins.directories', []),
        );

        if (config('plugins.install_mode') === 'dev') {
            $directories = array_merge($directories, config('plugins.dev_directories', []));
        }

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $pluginPath) {
                if (file_exists($pluginPath.DIRECTORY_SEPARATOR.'plugin.json')) {
                    $paths[] = $pluginPath;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    public function registryDiagnostics(): array
    {
        $issues = [];
        $pluginPaths = collect($this->pluginPaths());
        $registryPlugins = Plugin::query()->get();

        foreach ($registryPlugins as $plugin) {
            if ($plugin->enabled && ! $plugin->isInstalled()) {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'error',
                    'code' => 'enabled_uninstalled',
                    'message' => 'Plugin is enabled but marked uninstalled.',
                ];
            }

            if ($plugin->enabled && ! $plugin->available) {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'error',
                    'code' => 'enabled_missing_files',
                    'message' => 'Plugin is enabled but its files are not available on disk.',
                ];
            }

            if ($plugin->enabled && $plugin->validation_status !== 'valid') {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'warning',
                    'code' => 'enabled_invalid',
                    'message' => 'Plugin is enabled even though validation is not currently valid.',
                ];
            }

            if ($plugin->enabled && ! $plugin->isTrusted()) {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'error',
                    'code' => 'enabled_untrusted',
                    'message' => 'Plugin is enabled but has not been trusted by an administrator.',
                ];
            }

            if ($plugin->enabled && ! $plugin->hasVerifiedIntegrity()) {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'error',
                    'code' => 'enabled_integrity_unverified',
                    'message' => 'Plugin is enabled even though its integrity is not verified.',
                ];
            }

            if ($plugin->integrity_status === 'changed') {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'error',
                    'code' => 'plugin_files_changed',
                    'message' => 'Plugin files changed after trust and require a fresh review.',
                ];
            }

            if (! $this->trustWithoutReviewAllowed($plugin) && ! $this->approvedReviewForPlugin($plugin)) {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => $plugin->enabled ? 'error' : 'warning',
                    'code' => 'missing_install_review',
                    'message' => 'Plugin does not have an approved reviewed-install record for the current file set.',
                ];
            }

            if (($plugin->last_cleanup_mode ?? null) === 'purge') {
                $ownership = $plugin->data_ownership ?? [];

                foreach ($ownership['directories'] ?? [] as $directory) {
                    if (Storage::disk('local')->exists($directory)) {
                        $issues[] = [
                            'plugin_id' => $plugin->plugin_id,
                            'level' => 'warning',
                            'code' => 'purged_directory_still_exists',
                            'message' => "Declared plugin-owned directory [{$directory}] still exists after a purge uninstall.",
                        ];
                    }
                }

                foreach ($ownership['files'] ?? [] as $file) {
                    if (Storage::disk('local')->exists($file)) {
                        $issues[] = [
                            'plugin_id' => $plugin->plugin_id,
                            'level' => 'warning',
                            'code' => 'purged_file_still_exists',
                            'message' => "Declared plugin-owned file [{$file}] still exists after a purge uninstall.",
                        ];
                    }
                }

                foreach ($ownership['tables'] ?? [] as $table) {
                    if (Schema::hasTable($table)) {
                        $issues[] = [
                            'plugin_id' => $plugin->plugin_id,
                            'level' => 'warning',
                            'code' => 'purged_table_still_exists',
                            'message' => "Declared plugin-owned table [{$table}] still exists after a purge uninstall.",
                        ];
                    }
                }
            }

            if ($plugin->isInstalled() || ($plugin->last_cleanup_mode ?? null) !== 'purge') {
                foreach ($this->schemaManager->diagnostics($plugin->plugin_id, $plugin->schema_definition ?? []) as $diagnostic) {
                    $issues[] = [
                        'plugin_id' => $plugin->plugin_id,
                        ...$diagnostic,
                    ];
                }
            }
        }

        foreach ($pluginPaths as $pluginPath) {
            if (! $registryPlugins->contains(fn (Plugin $plugin) => $plugin->path === $pluginPath)) {
                $issues[] = [
                    'plugin_id' => basename($pluginPath),
                    'level' => 'info',
                    'code' => 'missing_registry_record',
                    'message' => 'Local plugin exists on disk but has not been discovered into the registry yet.',
                ];
            }
        }

        return $issues;
    }

    private function ownershipForPlugin(Plugin $plugin): array
    {
        try {
            if ($plugin->path && file_exists((string) $plugin->path.DIRECTORY_SEPARATOR.'plugin.json')) {
                return $this->manifestLoader->load((string) $plugin->path)->dataOwnership;
            }
        } catch (Throwable) {
            // Fall back to the last persisted ownership snapshot so uninstall can still proceed.
        }

        return $plugin->data_ownership ?? [
            'plugin_id' => $plugin->plugin_id,
            'table_prefix' => 'plugin_'.Str::of($plugin->plugin_id)->replace('-', '_')->lower()->value().'_',
            'tables' => [],
            'directories' => [],
            'files' => [],
            'default_cleanup_policy' => 'preserve',
        ];
    }

    private function runPluginUninstallHook(Plugin $plugin, string $cleanupMode, array $ownership, ?int $userId): void
    {
        if (! $plugin->path || ! file_exists(rtrim((string) $plugin->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.(string) $plugin->entrypoint)) {
            return;
        }

        if (! $plugin->isTrusted() || ! $plugin->hasVerifiedIntegrity() || $plugin->validation_status !== 'valid') {
            return;
        }

        require_once rtrim((string) $plugin->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$plugin->entrypoint;

        $instance = app($plugin->class_name);
        if (! $instance instanceof LifecyclePluginInterface) {
            return;
        }

        $instance->uninstall(new PluginUninstallContext(
            plugin: $plugin->fresh(),
            cleanupMode: $cleanupMode,
            dataOwnership: $ownership,
            user: $userId ? User::find($userId) : null,
        ));
    }

    private function purgeOwnedData(array $ownership): void
    {
        $this->schemaManager->purge(['tables' => collect($ownership['tables'] ?? [])
            ->map(fn (string $table) => ['name' => $table, 'columns' => [['type' => 'id']]])
            ->all()]);

        foreach ($ownership['files'] ?? [] as $file) {
            Storage::disk('local')->delete($file);
        }

        foreach ($ownership['directories'] ?? [] as $directory) {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }

    private function prepareRun(Plugin $plugin, array $attributes, array $options = []): PluginRun
    {
        $existingRunId = $options['existing_run_id'] ?? null;

        if (! $existingRunId) {
            return $this->startRun($plugin, $attributes);
        }

        $run = PluginRun::query()
            ->where('extension_plugin_id', $plugin->id)
            ->findOrFail($existingRunId);

        $resumeMessage = ($options['resume'] ?? false)
            ? 'Run resumed from its last saved checkpoint.'
            : ($run->summary ?: 'Run restarted.');

        $run->logs()->create([
            'level' => 'info',
            'message' => $resumeMessage,
            'context' => [
                'resume' => (bool) ($options['resume'] ?? false),
            ],
        ]);

        $run->update([
            ...$attributes,
            'status' => 'running',
            'summary' => $resumeMessage,
            'result' => null,
            'progress_message' => $resumeMessage,
            'cancel_requested' => false,
            'cancel_requested_at' => null,
            'cancelled_at' => null,
            'stale_at' => null,
            'finished_at' => null,
            'last_heartbeat_at' => now(),
            'started_at' => $run->started_at ?? now(),
        ]);

        return $run->fresh();
    }

    private function startRun(Plugin $plugin, array $attributes): PluginRun
    {
        return $plugin->runs()->create([
            ...$attributes,
            'status' => 'running',
            'progress' => 0,
            'progress_message' => 'Run queued and waiting for the worker to start.',
            'last_heartbeat_at' => now(),
            'started_at' => now(),
        ]);
    }

    private function finishRun(PluginRun $run, PluginActionResult $result): PluginRun
    {
        $run->logs()->create([
            'level' => $result->success ? 'info' : 'error',
            'message' => $result->summary,
            'context' => [
                'result' => $result->data,
            ],
        ]);

        $run->update([
            'status' => $result->status,
            'result' => $result->toArray(),
            'summary' => $result->summary,
            'progress' => (int) data_get($result->data, 'progress', $result->status === 'completed' ? 100 : $run->progress),
            'progress_message' => $result->summary,
            'last_heartbeat_at' => now(),
            'cancel_requested' => false,
            'cancel_requested_at' => null,
            'cancelled_at' => $result->status === 'cancelled' ? now() : null,
            'run_state' => in_array($result->status, ['cancelled', 'stale'], true) ? $run->run_state : null,
            'finished_at' => now(),
        ]);

        return $run->fresh();
    }

    private function failRun(PluginRun $run, string $message): PluginRun
    {
        $run->logs()->create([
            'level' => 'error',
            'message' => $message,
            'context' => [],
        ]);

        $run->update([
            'status' => 'failed',
            'summary' => $message,
            'progress_message' => $message,
            'last_heartbeat_at' => now(),
            'result' => [
                'status' => 'failed',
                'success' => false,
                'summary' => $message,
                'data' => [],
            ],
            'finished_at' => now(),
        ]);

        return $run->fresh();
    }

    private function approvedReviewForPlugin(Plugin $plugin): ?PluginInstallReview
    {
        return PluginInstallReview::query()
            ->where('plugin_id', $plugin->plugin_id)
            ->whereIn('status', ['approved', 'installed'])
            ->orderByDesc('installed_at')
            ->orderByDesc('approved_at')
            ->get()
            ->first(function (PluginInstallReview $review) use ($plugin): bool {
                return data_get($review->integrity_hashes, 'plugin_hash') === $plugin->plugin_hash;
            });
    }

    private function trustWithoutReviewAllowed(Plugin $plugin): bool
    {
        if ($plugin->source_type === 'bundled') {
            return true;
        }

        return config('plugins.install_mode') === 'dev'
            && $plugin->source_type === 'local_dev';
    }

    private function scanRequiredForReview(PluginInstallReview $review): bool
    {
        if (config('plugins.clamav.driver', 'clamav') === 'fake') {
            return false;
        }

        if (! config('plugins.clamav.required_for_trust', true)) {
            return false;
        }

        return ! ($review->source_type === 'local_dev' && config('plugins.install_mode') === 'dev');
    }

    private function reviewStagingRoot(PluginInstallReview $review): string
    {
        return rtrim((string) config('plugins.staging_directory'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'review-'.$review->id;
    }

    private function managedPluginDirectory(): string
    {
        return (string) (collect(config('plugins.directories', [base_path('plugins')]))->first() ?: base_path('plugins'));
    }

    private function normalizeRealPath(string $path): string
    {
        $resolved = realpath($path);

        return $resolved !== false ? $resolved : $path;
    }

    private function assertDevSourceAllowed(string $sourcePath): void
    {
        if (config('plugins.install_mode') !== 'dev') {
            throw new RuntimeException('Dev-source plugin reviews are only available when PLUGIN_INSTALL_MODE=dev.');
        }

        if (! $this->isPathWithinConfiguredDirectories($sourcePath, config('plugins.dev_directories', []))) {
            throw new RuntimeException('Dev-source plugin reviews must come from a configured PLUGIN_DEV_DIRECTORIES path.');
        }
    }

    private function parseGithubReleaseUrl(string $releaseUrl): array
    {
        $parts = parse_url($releaseUrl);
        if (! is_array($parts)) {
            throw new RuntimeException('GitHub release URL is not valid.');
        }

        $host = Str::lower((string) ($parts['host'] ?? ''));
        if (! in_array($host, config('plugins.github.allowed_hosts', ['github.com']), true)) {
            throw new RuntimeException("GitHub release URL host [{$host}] is not allowed.");
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = explode('/', $path);
        if (count($segments) < 6 || $segments[2] !== 'releases' || $segments[3] !== 'download') {
            throw new RuntimeException('GitHub release URL must use the /owner/repo/releases/download/tag/asset pattern.');
        }

        return [
            'host' => $host,
            'owner' => $segments[0],
            'repo' => $segments[1],
            'repository' => $segments[0].'/'.$segments[1],
            'tag' => $segments[4],
            'asset_name' => end($segments),
            'release_url' => $releaseUrl,
        ];
    }

    private function normalizeSha256(string $value, string $label): string
    {
        $normalized = Str::lower(trim($value));
        if (! preg_match('/^[a-f0-9]{64}$/', $normalized)) {
            throw new RuntimeException("{$label} must be a 64-character hexadecimal SHA-256 string.");
        }

        return $normalized;
    }

    private function downloadGithubReleaseArchive(string $releaseUrl, string $destinationPath): void
    {
        $timeout = (int) config('plugins.github.download_timeout', 60);
        $limit = (int) config('plugins.archive_limits.max_archive_bytes', 50 * 1024 * 1024);

        try {
            $response = Http::timeout($timeout)
                ->withOptions([
                    'sink' => $destinationPath,
                    'progress' => function ($downloadTotal, $downloadedBytes) use ($limit, $releaseUrl): void {
                        if ($limit <= 0) {
                            return;
                        }

                        if (($downloadTotal > 0 && $downloadTotal > $limit) || $downloadedBytes > $limit) {
                            throw new RuntimeException("GitHub release archive [{$releaseUrl}] exceeds the maximum allowed archive size.");
                        }
                    },
                ])
                ->get($releaseUrl);
        } catch (Throwable $exception) {
            if (is_file($destinationPath)) {
                File::delete($destinationPath);
            }

            throw $exception;
        }

        if (! $response->successful()) {
            if (is_file($destinationPath)) {
                File::delete($destinationPath);
            }

            throw new RuntimeException("Failed to download GitHub release archive from [{$releaseUrl}].");
        }
    }

    private function assertArchiveSizeWithinLimit(string $archivePath, string $displayPath): void
    {
        $size = filesize($archivePath);
        $limit = (int) config('plugins.archive_limits.max_archive_bytes', 50 * 1024 * 1024);

        if ($size !== false && $limit > 0 && $size > $limit) {
            throw new RuntimeException("Plugin archive [{$displayPath}] exceeds the maximum allowed archive size.");
        }
    }

    private function guardArchiveEntryBudget(int &$fileCount, int &$byteCount, int $entrySize, string $archivePath): void
    {
        $maxFileCount = (int) config('plugins.archive_limits.max_file_count', 500);
        $maxExtractedBytes = (int) config('plugins.archive_limits.max_extracted_bytes', 100 * 1024 * 1024);

        $fileCount++;
        $byteCount += max($entrySize, 0);

        if ($maxFileCount > 0 && $fileCount > $maxFileCount) {
            throw new RuntimeException("Plugin archive [{$archivePath}] contains too many files.");
        }

        if ($maxExtractedBytes > 0 && $byteCount > $maxExtractedBytes) {
            throw new RuntimeException("Plugin archive [{$archivePath}] exceeds the maximum extracted payload size.");
        }
    }

    private function isPathWithinConfiguredDirectories(string $path, array $directories): bool
    {
        foreach ($directories as $directory) {
            $prefix = rtrim((string) $directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            if ($prefix !== DIRECTORY_SEPARATOR && Str::startsWith($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function assertAbsolutePathWithinRoot(string $path, string $root, string $errorMessage): void
    {
        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR);
        $normalizedPath = rtrim($path, DIRECTORY_SEPARATOR);

        if ($normalizedPath !== $normalizedRoot && ! Str::startsWith($normalizedPath, $normalizedRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException($errorMessage);
        }
    }

    private function deleteFileOrFail(string $path, string $displayPath): void
    {
        if (is_file($path) && ! File::delete($path)) {
            throw new RuntimeException("Unable to delete {$displayPath}.");
        }
    }

    private function deleteDirectoryOrFail(string $path, string $displayPath): void
    {
        if (is_dir($path) && ! File::deleteDirectory($path)) {
            throw new RuntimeException("Unable to delete {$displayPath}.");
        }
    }

    private function resetReviewStagingRoot(string $stagingRoot): void
    {
        if (is_dir($stagingRoot)) {
            $this->deleteDirectoryOrFail($stagingRoot, "plugin review staging directory [{$stagingRoot}]");
        }

        File::ensureDirectoryExists($stagingRoot);
    }

    private function cleanupFailedReviewStage(
        PluginInstallReview $review,
        ?string $stagingRoot = null,
        ?callable $cleanup = null,
    ): void {
        if ($review->exists) {
            $review->delete();
        }

        try {
            if ($cleanup !== null) {
                $cleanup();
            }

            if ($stagingRoot && is_dir($stagingRoot)) {
                $this->deleteDirectoryOrFail($stagingRoot, "plugin review staging directory [{$stagingRoot}]");
            }
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }

    private function cleanupInstalledUploadedReview(PluginInstallReview $review): void
    {
        if ($review->staging_path && is_dir($review->staging_path)) {
            $this->deleteDirectoryOrFail($review->staging_path, "plugin review staging directory [{$review->staging_path}]");
        }

        if ($review->archive_path && is_file($review->archive_path)) {
            $this->deleteFileOrFail($review->archive_path, "reviewed plugin archive [{$review->archive_path}]");
        }

        $review->update([
            'archive_path' => null,
            'staging_path' => null,
            'extracted_path' => null,
        ]);
    }

    private function ensureReviewPayloadAvailable(PluginInstallReview $review): PluginInstallReview
    {
        $review = $review->fresh() ?? $review;

        if ($review->extracted_path && is_dir($review->extracted_path)) {
            return $review;
        }

        $this->discardMissingReviewPayload($review);

        throw new RuntimeException('This install review lost its staged plugin files after a rebuild or cleanup. Restage the plugin and try again.');
    }

    private function discardMissingReviewPayload(PluginInstallReview $review): void
    {
        $note = 'Staged plugin files are no longer available on disk. This review was discarded automatically after a rebuild or cleanup. Restage the plugin and try again.';

        $review->update([
            'status' => 'discarded',
            'scan_status' => 'scan_failed',
            'scan_summary' => $note,
            'review_notes' => $this->appendReviewNote($review->review_notes, $note),
            'archive_path' => null,
            'staging_path' => null,
            'extracted_path' => null,
        ]);
    }

    private function appendReviewNote(?string $existingNotes, string $note): string
    {
        $existingNotes = trim((string) $existingNotes);
        if ($existingNotes === '') {
            return $note;
        }

        if (Str::contains($existingNotes, $note)) {
            return $existingNotes;
        }

        return $existingNotes.PHP_EOL.PHP_EOL.$note;
    }

    private function refreshInstallReview(
        PluginInstallReview $review,
        ?string $sourcePath = null,
        ?string $stagingPath = null,
        ?string $extractedPath = null,
        ?string $archivePath = null,
        ?string $archiveFilename = null,
    ): PluginInstallReview {
        if (! $extractedPath || ! is_dir($extractedPath)) {
            throw new RuntimeException('Reviewed plugin payload directory is missing.');
        }

        $result = $this->validator->validatePath($extractedPath);
        $manifest = $result->manifest;
        $status = $result->valid ? 'review_ready' : 'staged';

        $review->update([
            'plugin_id' => $manifest?->id ?? $review->plugin_id,
            'plugin_name' => $manifest?->name ?? Arr::get($result->manifestData, 'name', $review->plugin_name),
            'plugin_version' => $manifest?->version ?? $review->plugin_version,
            'api_version' => $manifest?->apiVersion ?? $review->api_version,
            'source_path' => $sourcePath ?? $review->source_path,
            'archive_path' => $archivePath ?? $review->archive_path,
            'archive_filename' => $archiveFilename ?? $review->archive_filename,
            'staging_path' => $stagingPath ?? $review->staging_path,
            'extracted_path' => $extractedPath,
            'status' => $status,
            'validation_status' => $result->valid ? 'valid' : 'invalid',
            'validation_errors' => $result->errors,
            'capabilities' => $manifest?->capabilities ?? Arr::get($result->manifestData, 'capabilities', []),
            'hooks' => $manifest?->hooks ?? Arr::get($result->manifestData, 'hooks', []),
            'permissions' => $manifest?->permissions ?? Arr::get($result->manifestData, 'permissions', []),
            'schema_definition' => $manifest?->schema ?? Arr::get($result->manifestData, 'schema', []),
            'data_ownership' => $manifest?->dataOwnership ?? Arr::get($result->manifestData, 'data_ownership', []),
            'integrity_hashes' => $result->hashes,
            'manifest_snapshot' => $result->manifestData,
        ]);

        return $review->fresh();
    }

    private function extractPluginArchive(string $archivePath, string $extractRoot): string
    {
        $lowerName = Str::lower($archivePath);

        if (Str::endsWith($lowerName, '.zip')) {
            $zip = new ZipArchive;
            $opened = $zip->open($archivePath);
            if ($opened !== true) {
                throw new RuntimeException("Unable to open plugin archive [{$archivePath}] as a zip file.");
            }

            try {
                $this->extractZipArchiveSafely($zip, $extractRoot, $archivePath);
            } finally {
                $zip->close();
            }

            return $this->locateExtractedPluginRoot($extractRoot);
        }

        if (Str::endsWith($lowerName, ['.tar', '.tar.gz', '.tgz'])) {
            $archiveToExtract = $archivePath;

            if (Str::endsWith($lowerName, ['.tar.gz', '.tgz'])) {
                $compressedPath = $extractRoot.DIRECTORY_SEPARATOR.(Str::endsWith($lowerName, '.tgz') ? 'archive.tgz' : 'archive.tar.gz');
                File::copy($archivePath, $compressedPath);
                $compressed = new PharData($compressedPath);
                $compressed->decompress();
                $archiveToExtract = Str::endsWith($compressedPath, '.tgz')
                    ? Str::replaceLast('.tgz', '.tar', $compressedPath)
                    : Str::replaceLast('.gz', '', $compressedPath);
            }

            $phar = new PharData($archiveToExtract);
            $this->extractPharArchiveSafely($phar, $archiveToExtract, $extractRoot, $archivePath);

            return $this->locateExtractedPluginRoot($extractRoot);
        }

        throw new RuntimeException('Plugin archives must use .zip, .tar, .tar.gz, or .tgz.');
    }

    private function extractZipArchiveSafely(ZipArchive $zip, string $extractRoot, string $archivePath): void
    {
        $fileCount = 0;
        $byteCount = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (! is_array($stat) || ! isset($stat['name'])) {
                throw new RuntimeException("Plugin archive [{$archivePath}] contains an unreadable zip entry.");
            }

            $normalizedPath = $this->normalizeArchiveEntryPath((string) $stat['name'], $archivePath);

            $operatingSystem = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
                $fileType = ($attributes >> 16) & 0xF000;
                if ($fileType === 0xA000) {
                    throw new RuntimeException("Plugin archive [{$archivePath}] contains a symlink entry [{$stat['name']}], which is not allowed.");
                }
            }

            $destinationPath = $this->archiveDestinationPath($extractRoot, $normalizedPath);
            if (str_ends_with((string) $stat['name'], '/')) {
                File::ensureDirectoryExists($destinationPath);

                continue;
            }

            $this->guardArchiveEntryBudget($fileCount, $byteCount, (int) ($stat['size'] ?? 0), $archivePath);

            $stream = $zip->getStream((string) $stat['name']);
            if ($stream === false) {
                throw new RuntimeException("Plugin archive [{$archivePath}] contains an unreadable file entry [{$stat['name']}].");
            }

            File::ensureDirectoryExists(dirname($destinationPath));

            $handle = fopen($destinationPath, 'wb');
            if ($handle === false) {
                fclose($stream);
                throw new RuntimeException("Unable to write extracted plugin file [{$destinationPath}].");
            }

            stream_copy_to_stream($stream, $handle);
            fclose($stream);
            fclose($handle);
        }
    }

    private function extractPharArchiveSafely(PharData $phar, string $archiveToExtract, string $extractRoot, string $displayArchivePath): void
    {
        $fileCount = 0;
        $byteCount = 0;
        $iterator = new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $entry) {
            $relativePath = $this->relativePharEntryPath((string) $entry->getPathname(), $archiveToExtract);
            $normalizedPath = $this->normalizeArchiveEntryPath($relativePath, $displayArchivePath);

            if (method_exists($entry, 'isLink') && $entry->isLink()) {
                throw new RuntimeException("Plugin archive [{$displayArchivePath}] contains a symlink entry [{$relativePath}], which is not allowed.");
            }

            $destinationPath = $this->archiveDestinationPath($extractRoot, $normalizedPath);
            if ($entry->isDir()) {
                File::ensureDirectoryExists($destinationPath);

                continue;
            }

            if (! $entry->isFile()) {
                throw new RuntimeException("Plugin archive [{$displayArchivePath}] contains an unsupported entry type [{$relativePath}].");
            }

            $this->guardArchiveEntryBudget($fileCount, $byteCount, (int) $entry->getSize(), $displayArchivePath);

            $contents = file_get_contents($entry->getPathname());
            if ($contents === false) {
                throw new RuntimeException("Plugin archive [{$displayArchivePath}] contains an unreadable file entry [{$relativePath}].");
            }

            File::ensureDirectoryExists(dirname($destinationPath));
            File::put($destinationPath, $contents);
        }
    }

    private function normalizeArchiveEntryPath(string $entryName, string $archivePath): string
    {
        $normalized = str_replace('\\', '/', trim($entryName));
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        $normalized = preg_replace('#^(?:\./)+#', '', $normalized) ?? $normalized;
        $trimmed = trim($normalized, '/');

        if ($trimmed === '') {
            throw new RuntimeException("Plugin archive [{$archivePath}] contains an empty entry name.");
        }

        if (str_contains($trimmed, "\0") || preg_match('/^[A-Za-z]:\//', $trimmed) === 1 || str_starts_with($normalized, '/')) {
            throw new RuntimeException("Plugin archive [{$archivePath}] contains an absolute path entry [{$entryName}].");
        }

        $segments = explode('/', $trimmed);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException("Plugin archive [{$archivePath}] contains an unsafe path entry [{$entryName}].");
            }
        }

        return implode('/', $segments);
    }

    private function relativePharEntryPath(string $entryPath, string $archiveToExtract): string
    {
        $normalizedEntryPath = str_replace('\\', '/', $entryPath);
        $normalizedArchivePath = str_replace('\\', '/', (string) (realpath($archiveToExtract) ?: $archiveToExtract));
        $prefix = 'phar://'.rtrim($normalizedArchivePath, '/').'/';

        if (! Str::startsWith($normalizedEntryPath, $prefix)) {
            throw new RuntimeException("Unable to resolve archive entry path [{$entryPath}] during plugin archive inspection.");
        }

        return Str::after($normalizedEntryPath, $prefix);
    }

    private function archiveDestinationPath(string $extractRoot, string $normalizedPath): string
    {
        return rtrim($extractRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
    }

    private function locateExtractedPluginRoot(string $extractRoot): string
    {
        if (file_exists($extractRoot.DIRECTORY_SEPARATOR.'plugin.json')) {
            return $extractRoot;
        }

        $manifestFiles = collect(File::allFiles($extractRoot))
            ->filter(fn ($file) => $file->getFilename() === 'plugin.json')
            ->values();

        if ($manifestFiles->count() !== 1) {
            throw new RuntimeException('Plugin archive must contain exactly one plugin.json manifest.');
        }

        return $manifestFiles->first()->getPath();
    }

    private function determineSourceType(string $pluginPath, Plugin $existing): string
    {
        if (in_array($existing->source_type, config('plugins.source_types', []), true)
            && in_array($existing->source_type, ['staged_archive', 'github_release', 'uploaded_archive'], true)) {
            return $existing->source_type;
        }

        if ($this->isPathWithinConfiguredDirectories($pluginPath, config('plugins.bundled_directories', []))) {
            return 'bundled';
        }

        if ($this->isPathWithinConfiguredDirectories($pluginPath, config('plugins.dev_directories', []))) {
            return 'local_dev';
        }

        return 'local_directory';
    }

    private function determineSecurityState(Plugin $existing, PluginValidationResult $result, bool $available): array
    {
        $currentHashes = $this->normalizeHashSnapshot($result->hashes);
        $trustedHashes = $this->normalizeHashSnapshot($existing->trusted_hashes ?? []);
        $trustState = $existing->trust_state ?: 'pending_review';
        $trustReason = $existing->trust_reason;
        $integrityStatus = 'unknown';
        $integrityVerifiedAt = null;
        $enabled = (bool) $existing->enabled;

        if (! $available || $currentHashes === []) {
            $integrityStatus = 'missing';
            $enabled = false;

            if ($trustState !== 'blocked') {
                $trustState = 'pending_review';
                $trustReason = 'Plugin files are missing from disk and require review.';
            }

            return [
                'trust_state' => $trustState,
                'trust_reason' => $trustReason,
                'integrity_status' => $integrityStatus,
                'integrity_verified_at' => $integrityVerifiedAt,
                'enabled' => $enabled,
            ];
        }

        if ($trustedHashes === []) {
            $integrityStatus = 'unknown';
            $trustState = $trustState === 'blocked' ? 'blocked' : 'pending_review';
            $trustReason ??= 'Plugin discovered and awaiting admin review.';
        } elseif ($trustedHashes === $currentHashes) {
            $integrityStatus = 'verified';
            $integrityVerifiedAt = now();
        } else {
            $integrityStatus = 'changed';
            $enabled = false;

            if ($trustState !== 'blocked') {
                $trustState = 'pending_review';
                $trustReason = 'Plugin files changed since they were last trusted.';
            }
        }

        if (! $result->valid) {
            $enabled = false;

            if ($trustState === 'trusted') {
                $trustState = 'pending_review';
                $trustReason = 'Plugin validation no longer passes and requires review.';
            }
        }

        if ($trustState !== 'trusted' || $integrityStatus !== 'verified') {
            $enabled = false;
        }

        return [
            'trust_state' => $trustState,
            'trust_reason' => $trustReason,
            'integrity_status' => $integrityStatus,
            'integrity_verified_at' => $integrityVerifiedAt,
            'enabled' => $enabled,
        ];
    }

    private function normalizeHashSnapshot(array $hashes): array
    {
        return array_filter([
            'manifest_hash' => $hashes['manifest_hash'] ?? null,
            'entrypoint_hash' => $hashes['entrypoint_hash'] ?? null,
            'plugin_hash' => $hashes['plugin_hash'] ?? null,
        ]);
    }

    private function currentHashSnapshot(Plugin $plugin): array
    {
        return $this->normalizeHashSnapshot([
            'manifest_hash' => $plugin->manifest_hash,
            'entrypoint_hash' => $plugin->entrypoint_hash,
            'plugin_hash' => $plugin->plugin_hash,
        ]);
    }

    private function assertPluginLoadable(Plugin $plugin, bool $requireEnabled): void
    {
        if (! $plugin->isInstalled()) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] has been uninstalled and must be reinstalled before it can run.");
        }

        if (! $plugin->available) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is missing from disk.");
        }

        if ($plugin->validation_status !== 'valid') {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is not valid.");
        }

        if ($requireEnabled && ! $plugin->enabled) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is disabled.");
        }
    }

    private function assertPluginRunnable(Plugin $plugin): void
    {
        if (! $plugin->enabled) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is disabled.");
        }

        if ($plugin->isBlocked()) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] has been blocked by an administrator.");
        }

        if (! $plugin->isTrusted()) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] must be trusted by an administrator before it can run.");
        }

        if (! $plugin->hasVerifiedIntegrity()) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] integrity is not verified.");
        }
    }

    private function assertOwnedModelSelections(array $fields, array $payload, ?User $user): void
    {
        if (! $user || $user->isAdmin()) {
            return;
        }

        foreach ($fields as $field) {
            if (($field['type'] ?? null) !== 'model_select' || ($field['scope'] ?? null) !== 'owned') {
                continue;
            }

            $fieldId = $field['id'] ?? null;
            $modelClass = $field['model'] ?? null;
            $value = $fieldId ? ($payload[$fieldId] ?? null) : null;

            if (! $fieldId || ! $value || ! is_string($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            $ids = is_array($value) ? array_filter($value) : [$value];

            foreach ($ids as $id) {
                $model = $modelClass::query()->find($id);
                if (! $model) {
                    continue;
                }

                if (! array_key_exists('user_id', $model->getAttributes())) {
                    throw new RuntimeException("Owned plugin field [{$fieldId}] cannot be enforced because [{$modelClass}] does not expose a user_id column.");
                }

                if ((int) $model->getAttribute('user_id') !== (int) $user->id) {
                    throw new RuntimeException("You do not have access to the selected resource for [{$fieldId}].");
                }
            }
        }
    }
}
