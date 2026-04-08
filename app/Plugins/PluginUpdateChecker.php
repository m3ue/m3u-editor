<?php

namespace App\Plugins;

use App\Models\Plugin;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PluginUpdateChecker
{
    /**
     * Check all eligible plugins for available updates.
     *
     * @return array<string, array{plugin_id: string, current: ?string, latest: ?string, update_available: bool, error: ?string}>
     */
    public function checkAll(): array
    {
        if (! config('plugins.update_check.enabled', true)) {
            return [];
        }

        $plugins = Plugin::query()
            ->whereNotNull('repository')
            ->where('repository', '!=', '')
            ->where('update_check_enabled', true)
            ->where('installation_status', 'installed')
            ->get();

        $results = [];
        foreach ($plugins as $plugin) {
            $results[$plugin->plugin_id] = $this->check($plugin);
        }

        return $results;
    }

    /**
     * Check a single plugin for available updates via the GitHub Releases API.
     *
     * @return array{plugin_id: string, current: ?string, latest: ?string, update_available: bool, error: ?string}
     */
    public function check(Plugin $plugin): array
    {
        $result = [
            'plugin_id' => $plugin->plugin_id,
            'current' => $plugin->version,
            'latest' => null,
            'update_available' => false,
            'error' => null,
        ];

        if (! $plugin->repository) {
            $result['error'] = 'No repository configured.';

            return $result;
        }

        try {
            $release = $this->fetchLatestRelease($plugin->repository);

            if (! $release) {
                $result['error'] = 'No releases found.';
                $plugin->update(['last_update_check_at' => now()]);

                return $result;
            }

            $latestVersion = $this->normalizeVersion($release['tag_name'] ?? '');
            $result['latest'] = $latestVersion;

            $asset = $this->findPluginAsset($release['assets'] ?? []);
            $assetUrl = $asset['browser_download_url'] ?? null;

            $sha256 = $this->resolveSha256($release, $asset);

            $plugin->update([
                'latest_version' => $latestVersion,
                'latest_release_url' => $assetUrl,
                'latest_release_sha256' => $sha256,
                'latest_release_metadata' => [
                    'tag' => $release['tag_name'] ?? null,
                    'name' => $release['name'] ?? null,
                    'published_at' => $release['published_at'] ?? null,
                    'html_url' => $release['html_url'] ?? null,
                    'asset_name' => $asset['name'] ?? null,
                ],
                'last_update_check_at' => now(),
            ]);

            $result['update_available'] = $plugin->version
                && $latestVersion
                && version_compare(
                    ltrim($plugin->version, 'v'),
                    ltrim($latestVersion, 'v'),
                    '<',
                );

        } catch (Exception $exception) {
            $result['error'] = $exception->getMessage();
            Log::warning("Plugin update check failed for [{$plugin->plugin_id}]: {$exception->getMessage()}");

            $plugin->update(['last_update_check_at' => now()]);
        }

        return $result;
    }

    /**
     * Fetch the latest release from the GitHub Releases API.
     *
     * @return array<string, mixed>|null
     */
    private function fetchLatestRelease(string $repository): ?array
    {
        $request = Http::timeout(15)
            ->withHeaders([
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'm3u-editor-plugin-updater',
            ]);

        $token = config('plugins.update_check.github_token');
        if ($token) {
            $request = $request->withToken($token);
        }

        $response = $request->get("https://api.github.com/repos/{$repository}/releases/latest");

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new Exception("GitHub API returned status {$response->status()} for [{$repository}].");
        }

        return $response->json();
    }

    /**
     * Find the best plugin archive asset from a release's assets list.
     *
     * Prefers .zip, then .tar.gz / .tgz.
     *
     * @param  array<int, array<string, mixed>>  $assets
     * @return array<string, mixed>|null
     */
    private function findPluginAsset(array $assets): ?array
    {
        $zipAsset = null;
        $tarAsset = null;

        foreach ($assets as $asset) {
            $name = Str::lower($asset['name'] ?? '');

            if (Str::endsWith($name, '.sha256') || Str::endsWith($name, '.asc') || Str::endsWith($name, '.sig')) {
                continue;
            }

            if (Str::endsWith($name, '.zip')) {
                $zipAsset ??= $asset;
            } elseif (Str::endsWith($name, '.tar.gz') || Str::endsWith($name, '.tgz')) {
                $tarAsset ??= $asset;
            }
        }

        return $zipAsset ?? $tarAsset;
    }

    /**
     * Resolve the SHA-256 checksum for a release asset.
     *
     * Strategy:
     * 1. Look for a companion .sha256 asset file
     * 2. Parse the release body for a SHA-256 hash
     * 3. Return null if neither found
     */
    private function resolveSha256(array $release, ?array $asset): ?string
    {
        if (! $asset) {
            return null;
        }

        // Strategy 1: companion .sha256 asset file
        $companionName = ($asset['name'] ?? '').'.sha256';
        foreach ($release['assets'] ?? [] as $releaseAsset) {
            if (Str::lower($releaseAsset['name'] ?? '') === Str::lower($companionName)) {
                return $this->downloadCompanionSha256($releaseAsset['browser_download_url']);
            }
        }

        // Strategy 2: parse the release body
        $body = $release['body'] ?? '';
        if ($body) {
            return $this->parseSha256FromBody($body, $asset['name'] ?? '');
        }

        return null;
    }

    /**
     * Download and parse a companion .sha256 file.
     */
    private function downloadCompanionSha256(string $url): ?string
    {
        try {
            $request = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'm3u-editor-plugin-updater']);

            $token = config('plugins.update_check.github_token');
            if ($token) {
                $request = $request->withToken($token);
            }

            $response = $request->get($url);

            if ($response->successful()) {
                $content = trim($response->body());

                // Handle "hash  filename" format
                if (preg_match('/^([a-fA-F0-9]{64})\b/', $content, $matches)) {
                    return strtolower($matches[1]);
                }
            }
        } catch (Exception $exception) {
            Log::debug("Failed to download companion SHA-256 file: {$exception->getMessage()}");
        }

        return null;
    }

    /**
     * Parse a SHA-256 hash from the release body text.
     *
     * Supports common formats:
     * - "SHA-256: abc123..."
     * - "sha256: abc123..."
     * - "SHA256(`filename`) = abc123..."
     * - "`abc123...`"
     * - Markdown table with hash
     */
    private function parseSha256FromBody(string $body, string $assetName): ?string
    {
        // Look for a hash near the asset name first
        $escapedName = preg_quote($assetName, '/');
        if (preg_match("/(?:{$escapedName})[^a-fA-F0-9]*([a-fA-F0-9]{64})/i", $body, $matches)) {
            return strtolower($matches[1]);
        }

        // Generic SHA-256 label patterns
        if (preg_match('/SHA-?256\s*[:=`(]\s*`?([a-fA-F0-9]{64})`?/i', $body, $matches)) {
            return strtolower($matches[1]);
        }

        // Standalone 64-char hex surrounded by backticks
        if (preg_match('/`([a-fA-F0-9]{64})`/', $body, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Normalize a version tag by stripping a leading "v" prefix.
     */
    private function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'v');
    }
}
