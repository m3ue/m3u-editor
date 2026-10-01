<?php

namespace App\Http\Controllers;

use App\Enums\ImageProfile;
use App\Services\LogoCacheService;
use App\Settings\GeneralSettings;
use App\Support\PrivateNetworkGuard;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
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

            $profile = $this->resolveProfile($request);

            // Check if the logo is already cached (for this profile, if any)
            $cacheFile = LogoCacheService::findImage($originalUrl, $profile);
            if ($cacheFile) {
                return LogoCacheService::streamResponse($cacheFile);
            }

            // Fetch the logo from the remote URL
            $logoData = $this->fetchRemoteLogo($originalUrl);

            if (! $logoData) {
                return $this->returnPlaceholder();
            }

            $cacheFile = LogoCacheService::storeImage($originalUrl, $logoData['content'], $logoData['content_type'] ?? null, $profile);

            return LogoCacheService::streamResponse($cacheFile, $logoData['content_type'] ?? null);
        } catch (\Exception $e) {
            Log::error('Logo proxy error', [
                'encoded_url' => $encodedUrl,
                'error' => $e->getMessage(),
            ]);

            return $this->returnPlaceholder();
        }
    }

    /**
     * Generate a proxy URL for a given logo URL.
     *
     * When [$profile] is passed, the proxy serves (and caches) a copy sized
     * for that role (see ImageProfile) instead of the full-resolution source.
     * The URL carries the profile name, never pixel values, so it stays valid
     * when the sizes change under Settings > Assets. No profile leaves the
     * URL without a query string.
     */
    public static function generateProxyUrl(
        ?string $originalUrl,
        $internal = false,
        ?ImageProfile $profile = null
    ): string {
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

            // Always carried (the server ignores it while optimization is off),
            // so turning optimization on or off never changes artwork URLs.
            if ($profile) {
                $url .= '?'.http_build_query(['p' => $profile->value]);
            }
        }

        return $url;
    }

    /**
     * Fetch logo from remote URL
     */
    private function fetchRemoteLogo(string $url): ?array
    {
        try {
            // Every redirect hop is checked, not just $url: a public host
            // could otherwise 302 the proxy onto a private address.
            $response = PrivateNetworkGuard::get($url, fn (): PendingRequest => Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
                ]));

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
        } catch (InvalidArgumentException) {
            // Private, reserved, or non-http(s) destination: placeholder,
            // without a warning per request for a logo that is never cached.
            return null;
        } catch (\Exception $e) {
            Log::warning('Failed to fetch remote logo', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The size profile a request asks for: `?p=` by name, or a `?w=` / `?h=`
     * hint snapped to the nearest profile so arbitrary sizes never become
     * extra cached copies. Null serves the original.
     */
    private function resolveProfile(Request $request): ?ImageProfile
    {
        $named = $request->query('p');
        if (is_string($named) && $named !== '') {
            return ImageProfile::tryFrom($named);
        }

        $width = $request->query('w');
        if (is_numeric($width) && (int) $width > 0) {
            return ImageProfile::nearestToWidth((int) $width);
        }

        $height = $request->query('h');
        if (is_numeric($height) && (int) $height > 0) {
            return ImageProfile::Poster;
        }

        return null;
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

        $disk = Storage::disk('local');
        $expiryDays = (int) config('app.logo_cache_expiry_days', 30);
        $cleared = 0;
        $remainingBaseNames = [];
        $metaFiles = [];

        foreach ($disk->files(LogoCacheService::CACHE_DIRECTORY) as $file) {
            if (str_ends_with($file, '.meta.json')) {
                $metaFiles[] = $file;

                continue;
            }

            // Delete files last written more than X days ago
            $lastModified = Carbon::createFromTimestamp($disk->lastModified($file));
            if ($lastModified->diffInDays(now()) > $expiryDays) {
                $disk->delete($file);
                $cleared++;

                continue;
            }

            $remainingBaseNames[LogoCacheService::cacheBaseNameOf($file)] = true;
        }

        // An original and its size variants share one metadata file, which
        // goes once none of them are left.
        foreach ($metaFiles as $metaFile) {
            if (! isset($remainingBaseNames[basename($metaFile, '.meta.json')])) {
                $disk->delete($metaFile);
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
