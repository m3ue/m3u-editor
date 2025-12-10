<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
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
    public function serveLogo(Request $request, string $encodedUrl): Response|StreamedResponse
    {
        try {
            // Decode the URL
            $originalUrl = base64_decode(strtr($encodedUrl, '-_', '+/') . str_repeat('=', (4 - strlen($encodedUrl) % 4) % 4));

            // Validate the decoded URL
            if (! filter_var($originalUrl, FILTER_VALIDATE_URL)) {
                return $this->returnPlaceholder();
            }

            // Generate a cache key based on the original URL
            $cacheKey = 'logo_' . md5($originalUrl);
            $cacheFile = "cached-logos/{$cacheKey}";

            // Make sure the cache directory exists
            Storage::disk('local')->makeDirectory('cached-logos');

            // Check if the logo is already cached
            if (Storage::disk('local')->exists($cacheFile)) {
                return $this->serveFromCache($cacheFile);
            }

            // Fetch the logo from the remote URL
            $logoData = $this->fetchRemoteLogo($originalUrl);

            if (! $logoData) {
                return $this->returnPlaceholder();
            }

            // Cache the logo
            Storage::disk('local')->put($cacheFile, $logoData['content']);

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
        $proxyUrlOverride = config('proxy.url_override');
        $includeLogosInOverride = config('proxy.url_override_include_logos', true);

        if (empty($originalUrl) || ! filter_var($originalUrl, FILTER_VALIDATE_URL)) {
            $url = '/placeholder.png';
        } else {
            $encodedUrl = rtrim(strtr(base64_encode($originalUrl), '+/', '-_'), '=');
            // Use override URL only if enabled, not internal request, AND logos are included in override
            $url = $proxyUrlOverride && ! $internal && $includeLogosInOverride
                ? rtrim($proxyUrlOverride, '/') . "/logo-proxy/{$encodedUrl}"
                : url("/logo-proxy/{$encodedUrl}");
        }

        return $url;
    }

    /**
     * Fetch logo from remote URL
     */
    private function fetchRemoteLogo(string $url): ?array
    {
        try {
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
        $placeholderPath = public_path('placeholder.png');

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
        $cleared = 0;
        $logoFiles = Storage::disk('local')->files('cached-logos');

        foreach ($logoFiles as $file) {
            // Get file last modified timestamp
            $lastModified = Carbon::createFromTimestamp(Storage::disk('local')->lastModified($file));

            // If no metadata or file is older than X days, delete it
            if (now()->diffInDays($lastModified) > config('app.logo_cache_expiry_days', 30)) {
                Storage::disk('local')->delete($file);
                $cleared++;
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
        $logoFiles = Storage::disk('local')->files('cached-logos');
        foreach ($logoFiles as $file) {
            Storage::disk('local')->delete($file);
            $cleared++;
        }

        return $cleared;
    }

    public static function getCacheSize(): string
    {
        $totalSize = 0;
        $logoFiles = Storage::disk('local')->files('cached-logos');
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

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
