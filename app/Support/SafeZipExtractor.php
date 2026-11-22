<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Extracts zip archives from outside sources without letting an entry escape
 * the extraction root: absolute and `..` paths and symlink entries are
 * rejected, and the file count and extracted size are capped. Shared by the
 * plugin installer and the M3U TV web app installer, each with its own limits
 * and an archive label for error messages (e.g. "Plugin archive").
 */
class SafeZipExtractor
{
    public function __construct(
        private readonly string $archiveLabel,
        private readonly int $maxFileCount,
        private readonly int $maxExtractedBytes,
    ) {}

    public function extract(ZipArchive $zip, string $extractRoot, string $archivePath): void
    {
        $fileCount = 0;
        $byteCount = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (! is_array($stat) || ! isset($stat['name'])) {
                throw new RuntimeException("{$this->archiveLabel} [{$archivePath}] contains an unreadable zip entry.");
            }

            $normalizedPath = $this->normalizeEntryPath((string) $stat['name'], $archivePath);

            $operatingSystem = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
                $fileType = ($attributes >> 16) & 0xF000;
                if ($fileType === 0xA000) {
                    throw new RuntimeException("{$this->archiveLabel} [{$archivePath}] contains a symlink entry [{$stat['name']}], which is not allowed.");
                }
            }

            $destinationPath = $this->destinationPath($extractRoot, $normalizedPath);
            if (str_ends_with((string) $stat['name'], '/')) {
                File::ensureDirectoryExists($destinationPath);

                continue;
            }

            $this->guardEntryBudget($fileCount, $byteCount, (int) ($stat['size'] ?? 0), $archivePath);

            $stream = $zip->getStream((string) $stat['name']);
            if ($stream === false) {
                throw new RuntimeException("{$this->archiveLabel} [{$archivePath}] contains an unreadable file entry [{$stat['name']}].");
            }

            File::ensureDirectoryExists(dirname($destinationPath));

            $handle = fopen($destinationPath, 'wb');
            if ($handle === false) {
                fclose($stream);
                throw new RuntimeException("Unable to write extracted file [{$destinationPath}].");
            }

            stream_copy_to_stream($stream, $handle);
            fclose($stream);
            fclose($handle);
        }
    }

    public function normalizeEntryPath(string $entryName, string $archivePath): string
    {
        $normalized = str_replace('\\', '/', trim($entryName));
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        $normalized = preg_replace('#^(?:\./)+#', '', $normalized) ?? $normalized;
        $trimmed = trim($normalized, '/');

        if ($trimmed === '') {
            throw new RuntimeException("{$this->archiveLabel} [{$archivePath}] contains an empty entry name.");
        }

        if (str_contains($trimmed, "\0") || preg_match('/^[A-Za-z]:\//', $trimmed) === 1 || str_starts_with($normalized, '/')) {
            throw new RuntimeException("{$this->archiveLabel} [{$archivePath}] contains an absolute path entry [{$entryName}].");
        }

        $segments = explode('/', $trimmed);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException("{$this->archiveLabel} [{$archivePath}] contains an unsafe path entry [{$entryName}].");
            }
        }

        return implode('/', $segments);
    }

    public function destinationPath(string $extractRoot, string $normalizedPath): string
    {
        return rtrim($extractRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
    }

    public function guardEntryBudget(int &$fileCount, int &$byteCount, int $entrySize, string $archivePath): void
    {
        $fileCount++;
        $byteCount += max($entrySize, 0);

        if ($this->maxFileCount > 0 && $fileCount > $this->maxFileCount) {
            throw new RuntimeException("{$this->archiveLabel} [{$archivePath}] contains too many files.");
        }

        if ($this->maxExtractedBytes > 0 && $byteCount > $this->maxExtractedBytes) {
            throw new RuntimeException("{$this->archiveLabel} [{$archivePath}] exceeds the maximum extracted payload size.");
        }
    }
}
