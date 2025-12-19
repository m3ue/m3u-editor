<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class StrmFileMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'syncable_type',
        'syncable_id',
        'sync_location',
        'current_path',
        'current_url',
        'path_options',
    ];

    protected $casts = [
        'path_options' => 'array',
    ];

    /**
     * Get the parent syncable model (Channel for VOD, Series Episode, etc.)
     */
    public function syncable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Find or create a mapping for a syncable item
     */
    public static function findForSyncable(Model $syncable, string $syncLocation): ?self
    {
        return self::where('syncable_type', get_class($syncable))
            ->where('syncable_id', $syncable->id)
            ->where('sync_location', $syncLocation)
            ->first();
    }

    /**
     * Sync the .strm file - handles create, rename, and URL updates
     *
     * @param  Model  $syncable  The model being synced (Channel, Episode, etc.)
     * @param  string  $syncLocation  Base sync location path
     * @param  string  $expectedPath  The expected full path for the .strm file
     * @param  string  $url  The URL to store in the .strm file
     * @param  array  $pathOptions  The options used to generate the path (for tracking changes)
     * @return self The mapping record
     */
    public static function syncFile(
        Model $syncable,
        string $syncLocation,
        string $expectedPath,
        string $url,
        array $pathOptions = []
    ): self {
        $mapping = self::findForSyncable($syncable, $syncLocation);

        // Case 1: No existing mapping - create new file
        if (! $mapping) {
            return self::createNewFile($syncable, $syncLocation, $expectedPath, $url, $pathOptions);
        }

        // Case 2: Path changed - rename the file
        if ($mapping->current_path !== $expectedPath) {
            return self::renameFile($mapping, $expectedPath, $url, $pathOptions);
        }

        // Case 3: URL changed - update the file content
        if ($mapping->current_url !== $url) {
            return self::updateFileUrl($mapping, $url, $pathOptions);
        }

        // Case 4: Nothing changed - ensure path_options stay in sync
        if ($mapping->path_options != $pathOptions) {
            $mapping->path_options = $pathOptions;
            $mapping->save();
        }

        return $mapping;
    }

    /**
     * Create a new .strm file and mapping
     *
     * @throws RuntimeException If file creation fails
     */
    protected static function createNewFile(
        Model $syncable,
        string $syncLocation,
        string $path,
        string $url,
        array $pathOptions
    ): self {
        try {
            // Ensure directory exists (0755 = owner full, others read+execute)
            $directory = dirname($path);
            if (! is_dir($directory)) {
                if (! @mkdir($directory, 0755, true)) {
                    throw new RuntimeException("STRM Sync: Failed to create directory: {$directory}");
                }
            }

            // Write the file atomically using exclusive lock
            $result = @file_put_contents($path, $url, LOCK_EX);
            if ($result === false) {
                throw new RuntimeException("STRM Sync: Failed to write file: {$path}");
            }

            Log::debug('STRM Sync: Created new file', ['path' => $path]);

            // Create and return the mapping
            return self::create([
                'syncable_type' => get_class($syncable),
                'syncable_id' => $syncable->id,
                'sync_location' => $syncLocation,
                'current_path' => $path,
                'current_url' => $url,
                'path_options' => $pathOptions,
            ]);
        } catch (Throwable $e) {
            Log::error('STRM Sync: Failed to create file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Rename an existing .strm file
     *
     * @throws RuntimeException If rename or file creation fails
     */
    protected static function renameFile(
        self $mapping,
        string $newPath,
        string $url,
        array $pathOptions
    ): self {
        $oldPath = $mapping->current_path;
        $oldDirectory = dirname($oldPath);

        try {
            // Ensure new directory exists (0755 = owner full, others read+execute)
            $newDirectory = dirname($newPath);
            if (! is_dir($newDirectory)) {
                if (! @mkdir($newDirectory, 0755, true)) {
                    throw new RuntimeException("STRM Sync: Failed to create directory: {$newDirectory}");
                }
            }

            // Try to rename/move the file with race condition handling
            $fileRenamed = false;
            if (@file_exists($oldPath)) {
                try {
                    // Try to rename the file
                    if (@rename($oldPath, $newPath)) {
                        $fileRenamed = true;
                        Log::info('STRM Sync: Renamed file', ['from' => $oldPath, 'to' => $newPath]);
                    } else {
                        // Rename failed - try copy + delete as fallback (handles cross-device moves)
                        if (@copy($oldPath, $newPath)) {
                            $fileRenamed = true;
                            // Copy succeeded, try to delete old file (non-critical if it fails)
                            if (! @unlink($oldPath)) {
                                Log::warning('STRM Sync: Failed to delete old file after copy', ['path' => $oldPath]);
                            }
                            Log::info('STRM Sync: Moved file (copy+delete)', ['from' => $oldPath, 'to' => $newPath]);
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning('STRM Sync: Exception during file rename', [
                        'from' => $oldPath,
                        'to' => $newPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // If rename failed or old file didn't exist, create new file
            if (! $fileRenamed) {
                $result = @file_put_contents($newPath, $url, LOCK_EX);
                if ($result === false) {
                    throw new RuntimeException("STRM Sync: Failed to create file: {$newPath}");
                }
                Log::debug('STRM Sync: Created file (rename failed or old file missing)', ['path' => $newPath]);
            } elseif ($mapping->current_url !== $url) {
                // File was renamed, update URL if it changed
                $result = @file_put_contents($newPath, $url, LOCK_EX);
                if ($result === false) {
                    Log::warning('STRM Sync: Failed to update URL in renamed file', ['path' => $newPath]);
                }
            }

            // Clean up empty old directories (safe path handling)
            self::cleanupEmptyDirectories($oldDirectory, $mapping->sync_location);

            // Update the mapping
            $mapping->update([
                'current_path' => $newPath,
                'current_url' => $url,
                'path_options' => $pathOptions,
            ]);

            return $mapping;
        } catch (Throwable $e) {
            Log::error('STRM Sync: Failed to rename file', [
                'from' => $oldPath,
                'to' => $newPath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Update the URL in an existing .strm file
     */
    protected static function updateFileUrl(self $mapping, string $url, array $pathOptions = []): self
    {
        try {
            if (@file_exists($mapping->current_path)) {
                $result = @file_put_contents($mapping->current_path, $url, LOCK_EX);
                if ($result === false) {
                    Log::warning('STRM Sync: Failed to update URL', ['path' => $mapping->current_path]);
                } else {
                    Log::debug('STRM Sync: Updated URL', ['path' => $mapping->current_path]);
                }
            } else {
                // File doesn't exist, create it
                $directory = dirname($mapping->current_path);
                if (! is_dir($directory)) {
                    if (! @mkdir($directory, 0755, true)) {
                        Log::warning('STRM Sync: Failed to create directory for URL update', ['directory' => $directory]);

                        return $mapping;
                    }
                }
                $result = @file_put_contents($mapping->current_path, $url, LOCK_EX);
                if ($result === false) {
                    Log::warning('STRM Sync: Failed to recreate file with new URL', ['path' => $mapping->current_path]);

                    return $mapping;
                }
                Log::debug('STRM Sync: Recreated file with new URL', ['path' => $mapping->current_path]);
            }

            $mapping->update([
                'current_url' => $url,
                'path_options' => $pathOptions,
            ]);

            return $mapping;
        } catch (Throwable $e) {
            Log::warning('STRM Sync: Exception during URL update', [
                'path' => $mapping->current_path,
                'error' => $e->getMessage(),
            ]);

            return $mapping;
        }
    }

    /**
     * Delete the .strm file and its mapping
     */
    public function deleteFile(): void
    {
        try {
            if (@file_exists($this->current_path)) {
                if (! @unlink($this->current_path)) {
                    Log::warning('STRM Sync: Failed to delete file', ['path' => $this->current_path]);
                    // Still delete the mapping to avoid retrying endlessly
                } else {
                    Log::debug('STRM Sync: Deleted file', ['path' => $this->current_path]);
                }

                // Clean up empty directories
                self::cleanupEmptyDirectories(dirname($this->current_path), $this->sync_location);
            }
        } catch (Throwable $e) {
            Log::warning('STRM Sync: Exception during file deletion', [
                'path' => $this->current_path,
                'error' => $e->getMessage(),
            ]);
        }

        $this->delete();
    }

    /**
     * Clean up empty directories up to the sync location
     * Uses realpath() to prevent path traversal attacks
     */
    protected static function cleanupEmptyDirectories(string $directory, string $syncLocation): void
    {
        try {
            // Resolve real paths to prevent path traversal attacks
            $realSyncLocation = realpath(rtrim($syncLocation, '/'));
            if ($realSyncLocation === false) {
                return; // Sync location doesn't exist
            }

            $realDirectory = realpath($directory);
            if ($realDirectory === false) {
                return; // Directory doesn't exist
            }

            // Ensure directory is within sync location (prevent traversal)
            if (! str_starts_with($realDirectory . '/', $realSyncLocation . '/')) {
                Log::warning('STRM Sync: Directory cleanup blocked - path outside sync location', [
                    'directory' => $directory,
                    'sync_location' => $syncLocation,
                ]);

                return;
            }

            // Don't delete the sync location itself
            while ($realDirectory !== $realSyncLocation && strlen($realDirectory) > strlen($realSyncLocation)) {
                if (is_dir($realDirectory) && self::isDirectoryEmpty($realDirectory)) {
                    if (! @rmdir($realDirectory)) {
                        Log::debug('STRM Sync: Failed to remove empty directory (may be in use)', ['path' => $realDirectory]);
                        break;
                    }
                    Log::debug('STRM Sync: Removed empty directory', ['path' => $realDirectory]);
                    $realDirectory = dirname($realDirectory);
                } else {
                    break;
                }
            }
        } catch (Throwable $e) {
            Log::debug('STRM Sync: Exception during directory cleanup', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if a directory is empty using efficient early-exit approach
     */
    protected static function isDirectoryEmpty(string $directory): bool
    {
        if (! is_dir($directory)) {
            return false;
        }

        $handle = @opendir($directory);
        if ($handle === false) {
            return false;
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry !== '.' && $entry !== '..') {
                    return false;
                }
            }

            return true;
        } finally {
            closedir($handle);
        }
    }

    /**
     * Delete all mappings and files for syncables that no longer exist or are disabled
     */
    public static function cleanupOrphaned(string $syncableType, string $syncLocation): int
    {
        $count = 0;

        self::where('syncable_type', $syncableType)
            ->where('sync_location', $syncLocation)
            ->chunk(100, function ($mappings) use (&$count) {
                foreach ($mappings as $mapping) {
                    // Check if the syncable still exists and is enabled
                    $syncable = $mapping->syncable;
                    if (! $syncable || ! ($syncable->enabled ?? true)) {
                        $mapping->deleteFile();
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Clean up all orphaned files for all sync locations
     */
    public static function cleanupAllOrphaned(): array
    {
        $results = [];

        // Get unique sync locations and types
        $locations = self::select('syncable_type', 'sync_location')
            ->distinct()
            ->get();

        foreach ($locations as $location) {
            $count = self::cleanupOrphaned($location->syncable_type, $location->sync_location);
            if ($count > 0) {
                $results[] = [
                    'type' => $location->syncable_type,
                    'location' => $location->sync_location,
                    'cleaned' => $count,
                ];
            }
        }

        return $results;
    }
}
