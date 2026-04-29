<?php

namespace App\Services;

use App\Models\DvrRecording;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DvrHlsDownloaderService — Downloads HLS manifest + segments from m3u-proxy via HTTP.
 *
 * The m3u-proxy writes HLS files to its own container's /tmp/m3u-proxy-broadcasts/<id>/
 * directory, which is not shared with the editor container. Rather than coupling the
 * two services via a shared volume, we mirror the NetworkHlsController pattern: pull
 * the manifest and segments over HTTP from the proxy's /broadcast/<id>/live.m3u8 and
 * /broadcast/<id>/segment/<file>.ts endpoints.
 *
 * The downloaded files are placed under the dvr storage disk at live/<recording_uuid>/,
 * which is the path DvrPostProcessorService already falls back to when temp_path is null.
 */
class DvrHlsDownloaderService
{
    public function __construct(protected M3uProxyService $proxy) {}

    /**
     * Download the HLS manifest and all listed segments for a recording.
     *
     * @return string Absolute filesystem path to the downloaded live.m3u8.
     *
     * @throws Exception If the manifest cannot be fetched, no segments are listed, or any segment download fails.
     */
    public function download(DvrRecording $recording, string $disk): string
    {
        $networkId = $recording->proxy_network_id;
        if (! $networkId) {
            throw new Exception('Cannot download HLS: recording has no proxy_network_id');
        }

        $relDir = 'live/'.$recording->uuid;
        $localDir = Storage::disk($disk)->path($relDir);

        if (! is_dir($localDir) && ! mkdir($localDir, 0755, true)) {
            throw new Exception("Could not create local HLS directory: {$localDir}");
        }

        $segments = $this->downloadManifest($networkId, $localDir);

        if (empty($segments)) {
            throw new Exception("HLS manifest for broadcast {$networkId} contained no segments");
        }

        Log::info('DVR HLS download: starting', [
            'recording_id' => $recording->id,
            'network_id' => $networkId,
            'segment_count' => count($segments),
            'local_dir' => $localDir,
        ]);

        foreach ($segments as $segment) {
            $this->downloadSegment($networkId, $segment, $localDir);
        }

        Log::info('DVR HLS download: complete', [
            'recording_id' => $recording->id,
            'network_id' => $networkId,
            'segment_count' => count($segments),
        ]);

        return $localDir.'/live.m3u8';
    }

    /**
     * Fetch the HLS manifest from the proxy and write it locally.
     * Returns the list of segment filenames referenced in the manifest.
     *
     * @return array<int, string>
     *
     * @throws Exception
     */
    protected function downloadManifest(string $networkId, string $localDir): array
    {
        $url = rtrim($this->proxy->getApiBaseUrl(), '/')."/broadcast/{$networkId}/live.m3u8";

        $response = Http::timeout(15)
            ->withHeaders($this->authHeaders())
            ->get($url);

        if (! $response->successful()) {
            throw new Exception("Failed to fetch HLS manifest from proxy: HTTP {$response->status()} at {$url}");
        }

        $body = $response->body();
        if ($body === '') {
            throw new Exception("HLS manifest from proxy is empty: {$url}");
        }

        $manifestPath = $localDir.'/live.m3u8';
        if (file_put_contents($manifestPath, $body) === false) {
            throw new Exception("Could not write HLS manifest to: {$manifestPath}");
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $body)),
            fn (string $line) => $line !== '' && ! str_starts_with($line, '#')
        ));
    }

    /**
     * Download a single .ts segment from the proxy.
     *
     * @throws Exception
     */
    protected function downloadSegment(string $networkId, string $segment, string $localDir): void
    {
        $url = rtrim($this->proxy->getApiBaseUrl(), '/')."/broadcast/{$networkId}/segment/{$segment}";

        $response = Http::timeout(60)
            ->withHeaders($this->authHeaders())
            ->get($url);

        if (! $response->successful()) {
            throw new Exception("Failed to download segment {$segment} from proxy: HTTP {$response->status()}");
        }

        $localPath = $localDir.'/'.$segment;
        if (file_put_contents($localPath, $response->body()) === false) {
            throw new Exception("Could not write segment to: {$localPath}");
        }
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        $token = $this->proxy->getApiToken();

        return $token ? ['X-API-Token' => $token] : [];
    }
}
