<?php

namespace App\Services;

use App\Models\Asset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssetInventoryService
{
    /**
     * @return array<int, array{disk: string, directory: string, source: string}>
     */
    protected function directories(): array
    {
        return [
            ['disk' => 'local', 'directory' => 'cached-logos', 'source' => 'logo_cache'],
            ['disk' => 'public', 'directory' => 'assets/library', 'source' => 'upload'],
            ['disk' => 'public', 'directory' => 'managed-assets', 'source' => 'upload'],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function placeholderFiles(): array
    {
        return [
            'placeholder.png',
            'episode-placeholder.png',
            'vod-series-poster-placeholder.png',
        ];
    }

    public function sync(bool $prune = true): int
    {
        $rows = [];
        $seenKeys = [];

        foreach ($this->directories() as $directory) {
            $disk = Storage::disk($directory['disk']);

            foreach ($disk->allFiles($directory['directory']) as $path) {
                if ($this->shouldSkipFile($directory['source'], $path)) {
                    continue;
                }

                $row = $this->buildRow($directory['disk'], $path, $directory['source']);
                $rows[] = $row;
                $seenKeys[] = $this->uniqueKey($row['disk'], $row['path']);
            }
        }

        $publicDisk = Storage::disk('public');
        foreach ($this->placeholderFiles() as $placeholderPath) {
            if (! $publicDisk->exists($placeholderPath)) {
                continue;
            }

            $row = $this->buildRow('public', $placeholderPath, 'placeholder');
            $rows[] = $row;
            $seenKeys[] = $this->uniqueKey($row['disk'], $row['path']);
        }

        if (! empty($rows)) {
            Asset::query()->upsert(
                $rows,
                ['disk', 'path'],
                ['source', 'name', 'extension', 'mime_type', 'size_bytes', 'is_image', 'last_modified_at', 'updated_at']
            );
        }

        if ($prune) {
            $sources = collect($this->directories())
                ->pluck('source')
                ->push('placeholder')
                ->unique()
                ->values()
                ->all();

            Asset::query()
                ->whereIn('source', $sources)
                ->get()
                ->filter(fn (Asset $asset): bool => ! in_array($this->uniqueKey($asset->disk, $asset->path), $seenKeys, true))
                ->each(fn (Asset $asset) => $asset->delete());
        }

        return count($rows);
    }

    public function indexFile(string $disk, string $path, string $source = 'upload'): Asset
    {
        $row = $this->buildRow($disk, $path, $source);

        Asset::query()->updateOrCreate(
            ['disk' => $row['disk'], 'path' => $row['path']],
            [
                'source' => $row['source'],
                'name' => $row['name'],
                'extension' => $row['extension'],
                'mime_type' => $row['mime_type'],
                'size_bytes' => $row['size_bytes'],
                'is_image' => $row['is_image'],
                'last_modified_at' => $row['last_modified_at'],
            ]
        );

        return Asset::query()->where('disk', $row['disk'])->where('path', $row['path'])->firstOrFail();
    }

    public function deleteAsset(Asset $asset): void
    {
        $disk = Storage::disk($asset->disk);

        if ($asset->source === 'logo_cache') {
            $this->deleteCompanionLogoMetadata($disk, $asset->path);
        }

        if ($disk->exists($asset->path)) {
            $disk->delete($asset->path);
        }

        $asset->delete();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadataForAsset(Asset $asset): ?array
    {
        if ($asset->source !== 'logo_cache') {
            return null;
        }

        $disk = Storage::disk($asset->disk);
        $metaPath = $this->companionLogoMetadataPath($asset->path);

        if (! $metaPath || ! $disk->exists($metaPath)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get($metaPath), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRow(string $disk, string $path, string $source): array
    {
        $storage = Storage::disk($disk);
        $absolutePath = $storage->path($path);
        $name = basename($path);
        $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION) ?: '');
        $mimeType = File::mimeType($absolutePath) ?: null;
        $size = File::size($absolutePath);
        $lastModified = filemtime($absolutePath);
        $isImage = Str::startsWith((string) $mimeType, 'image/');

        return [
            'disk' => $disk,
            'path' => $path,
            'source' => $source,
            'name' => $name,
            'extension' => $extension !== '' ? $extension : null,
            'mime_type' => $mimeType,
            'size_bytes' => is_int($size) ? $size : null,
            'is_image' => $isImage,
            'last_modified_at' => is_int($lastModified) ? CarbonImmutable::createFromTimestamp($lastModified) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    protected function uniqueKey(string $disk, string $path): string
    {
        return $disk.':'.$path;
    }

    protected function shouldSkipFile(string $source, string $path): bool
    {
        if ($source !== 'logo_cache') {
            return false;
        }

        return str_ends_with(strtolower($path), '.json');
    }

    protected function deleteCompanionLogoMetadata(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): void
    {
        $metaPath = $this->companionLogoMetadataPath($path);

        if (! $metaPath) {
            return;
        }

        if ($disk->exists($metaPath)) {
            $disk->delete($metaPath);
        }
    }

    protected function companionLogoMetadataPath(string $path): ?string
    {
        if (str_ends_with(strtolower($path), '.json')) {
            return null;
        }

        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $metaPath = trim($directory !== '.' ? $directory : '', '/');

        return ($metaPath !== '' ? $metaPath.'/' : '').$filename.'.meta.json';
    }
}
