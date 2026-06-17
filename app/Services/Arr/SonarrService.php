<?php

namespace App\Services\Arr;

class SonarrService extends BaseArrService
{
    /**
     * @return array{ok: bool, version?: string, error?: string}
     */
    public function testConnection(): array
    {
        try {
            $response = $this->client()->get('/system/status');

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'error' => 'Server returned status: '.$response->status(),
                ];
            }

            $data = $response->json();

            return [
                'ok' => true,
                'version' => $data['version'] ?? 'unknown',
            ];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function fetchQualityProfiles(): array
    {
        $response = $this->client()->get('/qualityprofile');

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->map(fn ($profile) => [
                'id' => (int) ($profile['id'] ?? 0),
                'name' => $profile['name'] ?? 'Unnamed',
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, path: string, freeSpace?: int}>
     */
    public function fetchRootFolders(): array
    {
        $response = $this->client()->get('/rootfolder');

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->map(fn ($folder) => [
                'id' => (int) ($folder['id'] ?? 0),
                'path' => $folder['path'] ?? '',
                'freeSpace' => isset($folder['freeSpace']) ? (int) $folder['freeSpace'] : null,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term): array
    {
        $response = $this->client()->get('/series/lookup', ['term' => $term]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->map(fn ($item) => [
                'tvdbId' => $item['tvdbId'] ?? null,
                'title' => $item['title'] ?? 'Unknown',
                'titleSlug' => $item['titleSlug'] ?? null,
                'year' => $item['year'] ?? null,
                'overview' => $item['overview'] ?? null,
                'poster' => $item['remotePoster'] ?? null,
                'seasons' => $item['seasons'] ?? [],
                'status' => $item['status'] ?? null,
                'network' => $item['network'] ?? null,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function add(array $payload): array
    {
        $body = [
            'tvdbId' => $payload['tvdbId'] ?? null,
            'title' => $payload['title'] ?? null,
            'titleSlug' => $payload['titleSlug'] ?? null,
            'qualityProfileId' => $payload['qualityProfileId'] ?? $this->integration->quality_profile_id,
            'rootFolderPath' => $payload['rootFolderPath'] ?? $this->integration->root_folder_path,
            'monitored' => true,
            'addOptions' => [
                'searchForMissingEpisodes' => $payload['searchForMissingEpisodes'] ?? true,
            ],
        ];

        // Optionally restrict to specific seasons when provided
        if (! empty($payload['seasons'])) {
            $body['seasons'] = $payload['seasons'];
        }

        return $this->safeCall(
            fn () => $this->client()->post('/series', $body)->throw()->json(),
            'add series'
        );
    }

    /**
     * @return array{exists: bool, id?: int}
     */
    public function checkExists(int $externalId): array
    {
        $response = $this->client()->get('/series/lookup', ['term' => 'tvdb:'.$externalId]);

        if (! $response->successful()) {
            return ['exists' => false];
        }

        $items = $response->json() ?? [];
        $first = $items[0] ?? null;

        if (! $first || ! isset($first['id'])) {
            return ['exists' => false];
        }

        return ['exists' => true, 'id' => (int) $first['id']];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchReleases(int $contentId): array
    {
        $response = $this->client()->get('/release', ['seriesId' => $contentId]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->map(fn ($release) => [
                'guid' => $release['guid'] ?? null,
                'title' => $release['title'] ?? 'Unknown',
                'indexerId' => $release['indexerId'] ?? null,
                'size' => $release['size'] ?? 0,
                'quality' => $release['quality']['quality']['name'] ?? 'Unknown',
                'protocol' => $release['protocol'] ?? 'unknown',
                'rejections' => $release['rejections'] ?? [],
                'approved' => empty($release['rejections']),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string}
     */
    public function downloadRelease(array $payload): array
    {
        return $this->safeCall(
            function () use ($payload) {
                $this->client()
                    ->post('/release', [
                        'guid' => $payload['guid'] ?? null,
                        'indexerId' => $payload['indexerId'] ?? null,
                        'seriesId' => $payload['seriesId'] ?? null,
                    ])
                    ->throw();

                return true;
            },
            'download release'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchQueue(): array
    {
        $response = $this->client()->get('/queue', ['includeSeries' => 'true']);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json()['records'] ?? [])
            ->map(function ($item) {
                $size = (int) ($item['size'] ?? 0);
                $sizeLeft = (int) ($item['sizeleft'] ?? 0);
                $progress = $size > 0 ? (int) round((1 - ($sizeLeft / $size)) * 100) : 0;

                $series = $item['series'] ?? null;

                return [
                    'id' => $item['id'] ?? null,
                    'title' => $series['title'] ?? $item['title'] ?? 'Unknown',
                    'status' => $item['status'] ?? 'unknown',
                    'progress' => max(0, min(100, $progress)),
                    'size' => $size,
                    'sizeLeft' => $sizeLeft,
                    'timeLeft' => $item['timeleft'] ?? null,
                ];
            })
            ->values()
            ->all();
    }
}
