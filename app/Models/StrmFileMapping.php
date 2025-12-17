<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class StrmFileMapping extends Model
{
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
            return self::updateFileUrl($mapping, $url);
        }

        // Case 4: Nothing changed - just return the mapping
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
        // Ensure directory exists (0755 = owner full, others read+execute)
        $directory = dirname($path);
        if (! is_dir($directory)) {
            if (! mkdir($directory, 0755, true)) {
                throw new RuntimeException("STRM Sync: Failed to create directory: {$directory}");
            }
        }

        // Write the file and check for errors
        $result = file_put_contents($path, $url);
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

        // Ensure new directory exists (0755 = owner full, others read+execute)
        $newDirectory = dirname($newPath);
        if (! is_dir($newDirectory)) {
            if (! mkdir($newDirectory, 0755, true)) {
                throw new RuntimeException("STRM Sync: Failed to create directory: {$newDirectory}");
            }
        }

        // Rename or create the file
        if (file_exists($oldPath)) {
            // Try to rename the file
            if (! @rename($oldPath, $newPath)) {
                // Rename failed - try copy + delete as fallback (handles cross-device moves)
                if (! @copy($oldPath, $newPath)) {
                    throw new RuntimeException("STRM Sync: Failed to rename/copy file from {$oldPath} to {$newPath}");
                }
                // Copy succeeded, try to delete old file (non-critical if it fails)
                if (! @unlink($oldPath)) {
                    Log::warning('STRM Sync: Failed to delete old file after copy', ['path' => $oldPath]);
                }
                Log::info('STRM Sync: Moved file (copy+delete)', ['from' => $oldPath, 'to' => $newPath]);
            } else {
                Log::info('STRM Sync: Renamed file', ['from' => $oldPath, 'to' => $newPath]);
            }

            // Update URL if it changed
            if ($mapping->current_url !== $url) {
                $result = file_put_contents($newPath, $url);
                if ($result === false) {
                    Log::warning('STRM Sync: Failed to update URL in renamed file', ['path' => $newPath]);
                }
            }

            // Clean up empty old directories
            self::cleanupEmptyDirectories($oldDirectory, $mapping->sync_location);
        } else {
            // Old file doesn't exist, create new one
            $result = file_put_contents($newPath, $url);
            if ($result === false) {
                throw new RuntimeException("STRM Sync: Failed to create file: {$newPath}");
            }
            Log::debug('STRM Sync: Created file (old file missing)', ['path' => $newPath]);
        }

        // Update the mapping
        $mapping->update([
            'current_path' => $newPath,
            'current_url' => $url,
            'path_options' => $pathOptions,
        ]);

        return $mapping;
    }

    /**
     * Update the URL in an existing .strm file
     */
    protected static function updateFileUrl(self $mapping, string $url): self
    {
        if (file_exists($mapping->current_path)) {
            $result = file_put_contents($mapping->current_path, $url);
            if ($result === false) {
                Log::warning('STRM Sync: Failed to update URL', ['path' => $mapping->current_path]);
            } else {
                Log::debug('STRM Sync: Updated URL', ['path' => $mapping->current_path]);
            }
        } else {
            // File doesn't exist, create it
            $directory = dirname($mapping->current_path);
            if (! is_dir($directory)) {
                if (! mkdir($directory, 0755, true)) {
                    Log::warning('STRM Sync: Failed to create directory for URL update', ['directory' => $directory]);

                    return $mapping;
                }
            }
            $result = file_put_contents($mapping->current_path, $url);
            if ($result === false) {
                Log::warning('STRM Sync: Failed to recreate file with new URL', ['path' => $mapping->current_path]);

                return $mapping;
            }
            Log::debug('STRM Sync: Recreated file with new URL', ['path' => $mapping->current_path]);
        }

        $mapping->update(['current_url' => $url]);

        return $mapping;
    }

    /**
     * Delete the .strm file and its mapping
     */
    public function deleteFile(): void
    {
        if (file_exists($this->current_path)) {
            if (! @unlink($this->current_path)) {
                Log::warning('STRM Sync: Failed to delete file', ['path' => $this->current_path]);
                // Still delete the mapping to avoid retrying endlessly
            } else {
                Log::debug('STRM Sync: Deleted file', ['path' => $this->current_path]);
            }

            // Clean up empty directories
            self::cleanupEmptyDirectories(dirname($this->current_path), $this->sync_location);
        }

        $this->delete();
    }

    /**
     * Clean up empty directories up to the sync location
     */
    protected static function cleanupEmptyDirectories(string $directory, string $syncLocation): void
    {
        $syncLocation = rtrim($syncLocation, '/');

        // Don't delete the sync location itself
        while ($directory !== $syncLocation && strlen($directory) > strlen($syncLocation)) {
            if (is_dir($directory) && self::isDirectoryEmpty($directory)) {
                if (! @rmdir($directory)) {
                    Log::debug('STRM Sync: Failed to remove empty directory (may be in use)', ['path' => $directory]);
                    break;
                }
                Log::debug('STRM Sync: Removed empty directory', ['path' => $directory]);
                $directory = dirname($directory);
            } else {
                break;
            }
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

        $handle = opendir($directory);
        if ($handle === false) {
            return false;
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry !== '.' && $entry !== '..') {
                closedir($handle);

                return false;
            }
        }

        closedir($handle);

        return true;
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
