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
    public static function generateProxyUrl(?string $originalUrl, $internal = false): string
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
        if ($this->isPrivateUrl($url)) {
            return null;
        }

        try {
            /** @var HttpClientResponse $response */
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
                ])->get($url);

            if (! $response->successful()) {
                return null;
            }

            $content = $response->body();

            // Check file size (limit to 5MB)
            if (strlen($content) > 5 * 1024 * 1024) {
                return null;
            }

            $contentType = $response->header('Content-Type');

            // Some CDNs/origins (notably Cloudflare workers and Express-based image
            // servers) return image bytes without a Content-Type header. Fall back
            // to sniffing the body so those logos still proxy correctly.
            if (! $contentType || ! str_starts_with($contentType, 'image/')) {
                $sniffed = $this->sniffImageMimeType($content);
                if (! $sniffed) {
                    return null;
                }
                $contentType = $sniffed;
            }

            // Sanitize SVG to strip potential XSS vectors before caching.
            if ($contentType === 'image/svg+xml') {
                $content = $this->sanitizeSvg($content);
                if ($content === null) {
                    return null;
                }
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
     * Check if the given URL resolves to a private/reserved IP address.
     */
    private function isPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return true;
        }

        $ip = gethostbyname($host);

        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Detect image MIME type from raw bytes. Returns null if not a recognised image.
     */
    private function sniffImageMimeType(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        $info = @getimagesizefromstring($content);
        if (is_array($info) && ! empty($info['mime']) && str_starts_with($info['mime'], 'image/')) {
            return $info['mime'];
        }

        // getimagesizefromstring does not recognise SVG — detect it via the opening tag.
        $head = ltrim(substr($content, 0, 1024));
        if ($head !== '' && preg_match('/^(<\?xml[^>]*>\s*)?<svg[\s>\/]/i', $head)) {
            return 'image/svg+xml';
        }

        return null;
    }

    /**
     * Strip XSS vectors from SVG bytes. Returns null if the SVG cannot be parsed.
     */
    private function sanitizeSvg(string $content): ?string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($content, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if (! $loaded || ! $dom->documentElement) {
            return null;
        }

        $xpath = new \DOMXPath($dom);

        // Remove elements that can execute code, using case-insensitive local-name matching.
        $unsafeTagExpr = implode(' or ', array_map(
            fn (string $tag) => "translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='{$tag}'",
            ['script', 'foreignobject', 'iframe', 'object', 'embed']
        ));
        foreach (iterator_to_array($xpath->query("//*[{$unsafeTagExpr}]") ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Strip event-handler attributes and dangerous URI values from all remaining elements.
        foreach (iterator_to_array($dom->getElementsByTagName('*')) as $element) {
            $toRemove = [];

            foreach ($element->attributes ?? [] as $attr) {
                if (preg_match('/^on\w+$/i', $attr->localName)) {
                    $toRemove[] = $attr->nodeName;

                    continue;
                }

                if (in_array(strtolower($attr->localName), ['href', 'src', 'action', 'formaction'])) {
                    $normalized = strtolower(preg_replace('/[\x00-\x1f\s]/', '', $attr->value));
                    if (str_starts_with($normalized, 'javascript:') || str_starts_with($normalized, 'data:text')) {
                        $toRemove[] = $attr->nodeName;
                    }
                }
            }

            foreach ($toRemove as $attrName) {
                $element->removeAttribute($attrName);
            }

            // xlink:href is a namespaced attribute and needs separate handling.
            $xlinkHref = $element->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
            if ($xlinkHref !== '') {
                $normalized = strtolower(preg_replace('/[\x00-\x1f\s]/', '', $xlinkHref));
                if (str_starts_with($normalized, 'javascript:') || str_starts_with($normalized, 'data:text')) {
                    $element->removeAttributeNS('http://www.w3.org/1999/xlink', 'href');
                }
            }
        }

        $sanitized = $dom->saveXML();

        return $sanitized !== false ? $sanitized : null;
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
