<?php

namespace App\Http\Controllers;

use App\Services\LogoCacheService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogoProxyController extends Controller
{
    /**
     * Serve a cached logo from an encoded URL
     */
    public function serveLogo(Request $request, string $encodedUrl, ?string $filename = null): Response|StreamedResponse
    {
        try {
            // Decode the URL
            $originalUrl = base64_decode(strtr($encodedUrl, '-_', '+/').str_repeat('=', (4 - strlen($encodedUrl) % 4) % 4));

            // Validate the decoded URL
            if (! filter_var($originalUrl, FILTER_VALIDATE_URL)) {
                return $this->returnPlaceholder();
            }

            // Make sure the cache directory exists
            Storage::disk('local')->makeDirectory(LogoCacheService::CACHE_DIRECTORY);

            // Check if the logo is already cached
            $cacheFile = LogoCacheService::findCacheFileForUrl($originalUrl);
            if ($cacheFile && Storage::disk('local')->exists($cacheFile)) {
                return $this->serveFromCache($cacheFile);
            }

            // Fetch the logo from the remote URL
            $logoData = $this->fetchRemoteLogo($originalUrl);

            if (! $logoData) {
                return $this->returnPlaceholder();
            }

            $extension = LogoCacheService::normalizeExtensionFromContentType(
                $logoData['content_type'] ?? null,
                $originalUrl
            );

            $cacheFile = LogoCacheService::cacheFileForUrl($originalUrl, $extension);

            // Cache the logo and metadata
            Storage::disk('local')->put($cacheFile, $logoData['content']);
            LogoCacheService::writeCacheMetadata($originalUrl, $cacheFile, $logoData['content_type'] ?? null);

            return $this->serveFromCache($cacheFile, $logoData['content_type']);
        } catch (\Exception $e) {
            Log::error('Logo proxy error', [
                'encoded_url' => $encodedUrl,
                'error' => $e->getMessage(),
            ]);

            return $this->returnPlaceholder();
        }
    }

    /**
     * Generate a proxy URL for a given logo URL
     */
    public static function generateProxyUrl(string $originalUrl, $internal = false): string
    {
        // Get the config values (takes priority over settings values)
        $proxyUrlOverride = config('proxy.url_override');
        $includeLogosInOverride = config('proxy.url_override_include_logos', true);

        // See if override settings apply
        try {
            $settings = app(GeneralSettings::class);
            if (! $proxyUrlOverride || empty($proxyUrlOverride)) {
                // Get from settings if not set in config
                $proxyUrlOverride = $settings->url_override ?? null;
            }
            if (config('proxy.url_override_include_logos') === null) {
                // Get from settings if not set in config
                $includeLogosInOverride = $settings->url_override_include_logos;
            }
        } catch (\Exception $e) {
        }

        if (empty($originalUrl) || ! filter_var($originalUrl, FILTER_VALIDATE_URL)) {
            $url = LogoCacheService::getPlaceholderUrl('logo');
        } else {
            $encodedUrl = rtrim(strtr(base64_encode($originalUrl), '+/', '-_'), '=');
            $filename = LogoCacheService::buildProxyFilename($originalUrl);
            // Use override URL only if enabled, not internal request, AND logos are included in override
            $url = $proxyUrlOverride && ! $internal && $includeLogosInOverride
                ? rtrim($proxyUrlOverride, '/')."/logo-proxy/{$encodedUrl}/{$filename}"
                : url("/logo-proxy/{$encodedUrl}/{$filename}");
        }

        return $url;
    }

    /**
     * Fetch logo from remote URL
     */
    private function fetchRemoteLogo(string $url): ?array
    {
        try {
            /** @var HttpClientResponse $response */
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
                ])->get($url);

            if (! $response->successful()) {
                return null;
            }

            $contentType = $response->header('Content-Type');

            // Validate that it's an image
            if (! str_starts_with($contentType, 'image/')) {
                return null;
            }

            $content = $response->body();

            // Check file size (limit to 5MB)
            if (strlen($content) > 5 * 1024 * 1024) {
                return null;
            }

            return [
                'content' => $content,
                'content_type' => $contentType,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to fetch remote logo', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Serve logo from cache
     */
    private function serveFromCache(string $cacheFile, ?string $contentType = null): StreamedResponse
    {
        $filePath = Storage::disk('local')->path($cacheFile);

        if (! $contentType) {
            // Try to determine content type from file
            $contentType = $this->getContentTypeFromFile($filePath);
        }

        return response()->stream(function () use ($filePath) {
            $stream = fopen($filePath, 'rb');
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=2592000', // 30 days
            'Expires' => now()->addDays(30)->format('D, d M Y H:i:s \G\M\T'),
            'Last-Modified' => date('D, d M Y H:i:s \G\M\T', filemtime($filePath)),
        ]);
    }

    /**
     * Return placeholder image
     */
    private function returnPlaceholder(): StreamedResponse
    {
        $configuredPlaceholderUrl = LogoCacheService::getPlaceholderUrl('logo');
        $configuredPlaceholderPath = parse_url($configuredPlaceholderUrl, PHP_URL_PATH);
        $placeholderPath = $configuredPlaceholderPath
            ? public_path(ltrim($configuredPlaceholderPath, '/'))
            : public_path('placeholder.png');

        if (! file_exists($placeholderPath)) {
            // Return a minimal 1x1 transparent PNG if placeholder doesn't exist
            $transparentPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

            return response()->stream(function () use ($transparentPng) {
                echo $transparentPng;
            }, 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=86400', // 1 day
            ]);
        }

        return response()->stream(function () use ($placeholderPath) {
            $stream = fopen($placeholderPath, 'rb');
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400', // 1 day
        ]);
    }

    /**
     * Get content type from file
     */
    private function getContentTypeFromFile(string $filePath): string
    {
        $mimeType = mime_content_type($filePath);

        // Fallback to common image types if detection fails
        if (! $mimeType || ! str_starts_with($mimeType, 'image/')) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            return match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                default => 'image/png',
            };
        }

        return $mimeType;
    }

    /**
     * Clear expired cache entries
     */
    public function clearExpiredCache(): int
    {
        try {
            $settings = app(GeneralSettings::class);
            if ($settings->logo_cache_permanent) {
                return 0;
            }
        } catch (\Exception $e) {
        }

        $cleared = 0;
        $logoFiles = Storage::disk('local')->files(LogoCacheService::CACHE_DIRECTORY);

        if (empty($logoFiles)) {
            return 0;
        }

        foreach ($logoFiles as $file) {
            if (str_ends_with($file, '.meta.json')) {
                continue;
            }

            // Get file last modified timestamp
            $lastModified = Carbon::createFromTimestamp(Storage::disk('local')->lastModified($file));

            // If no metadata or file is older than X days, delete it
            if (now()->diffInDays($lastModified) > config('app.logo_cache_expiry_days', 30)) {
                Storage::disk('local')->delete($file);
                $cleared++;

                $metaFile = LogoCacheService::CACHE_DIRECTORY.'/'.pathinfo($file, PATHINFO_FILENAME).'.meta.json';
                if (Storage::disk('local')->exists($metaFile)) {
                    Storage::disk('local')->delete($metaFile);
                    $cleared++;
                }
            }
        }

        return $cleared;
    }

    /**
     * Clear the entire logo cache
     */
    public function clearCache(): int
    {
        $cleared = 0;
        $logoFiles = Storage::disk('local')->files(LogoCacheService::CACHE_DIRECTORY);
        foreach ($logoFiles as $file) {
            Storage::disk('local')->delete($file);
            $cleared++;
        }

        return $cleared;
    }

    public static function getCacheSize(): string
    {
        $totalSize = 0;
        $logoFiles = Storage::disk('local')->files(LogoCacheService::CACHE_DIRECTORY);
        foreach ($logoFiles as $file) {
            $totalSize += Storage::disk('local')->size($file);
        }

        return self::humanFileSize($totalSize);
    }

    private static function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
