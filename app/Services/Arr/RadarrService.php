<?php

namespace App\Services\Arr;

class RadarrService extends BaseArrService
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
        $response = $this->client()->get('/movie/lookup', ['term' => $term]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->map(function ($item) {
                $fanart = collect($item['images'] ?? [])->firstWhere('coverType', 'fanart');
                $ratings = $item['ratings'] ?? [];
                $rating = null;
                if (! empty($ratings['imdb']['value'])) {
                    $rating = [
                        'value' => round((float) $ratings['imdb']['value'], 1),
                        'votes' => (int) ($ratings['imdb']['votes'] ?? 0),
                        'source' => 'imdb',
                    ];
                } elseif (! empty($ratings['tmdb']['value'])) {
                    $rating = [
                        'value' => round((float) $ratings['tmdb']['value'], 1),
                        'votes' => (int) ($ratings['tmdb']['votes'] ?? 0),
                        'source' => 'tmdb',
                    ];
                }

                return [
                    'tmdbId' => $item['tmdbId'] ?? null,
                    'title' => $item['title'] ?? 'Unknown',
                    'titleSlug' => $item['titleSlug'] ?? null,
                    'year' => $item['year'] ?? null,
                    'overview' => $item['overview'] ?? null,
                    'poster' => $item['remotePoster'] ?? null,
                    'fanart' => $fanart ? ($fanart['remoteUrl'] ?? null) : null,
                    'runtime' => $item['runtime'] ?? null,
                    'genres' => $item['genres'] ?? [],
                    'status' => $item['status'] ?? null,
                    'certification' => $item['certification'] ?? null,
                    'rating' => $rating,
                    'existsInLibrary' => isset($item['id']),
                    'libraryId' => isset($item['id']) ? (int) $item['id'] : null,
                    'hasFile' => ($item['hasFile'] ?? false) === true || isset($item['movieFile']),
                    'fileQuality' => isset($item['movieFile']) ? ($item['movieFile']['quality']['quality']['name'] ?? null) : null,
                    'fileSize' => isset($item['movieFile']) ? ($item['movieFile']['size'] ?? null) : null,
                    'images' => $item['images'] ?? [],
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function add(array $payload): array
    {
        $qualityProfileId = (int) ($payload['qualityProfileId'] ?? $this->integration->quality_profile_id);
        $rootFolderPath = $payload['rootFolderPath'] ?? $this->integration->root_folder_path;

        if (! $qualityProfileId || ! $rootFolderPath) {
            return [
                'ok' => false,
                'error' => __('Radarr integration ":name" is missing a quality profile or root folder. Please configure it in Settings → Integrations.', [
                    'name' => $this->integration->name,
                ]),
            ];
        }

        $body = [
            'tmdbId' => $payload['tmdbId'] ?? null,
            'title' => $payload['title'] ?? null,
            'titleSlug' => $payload['titleSlug'] ?? null,
            'images' => $payload['images'] ?? [],
            'qualityProfileId' => $qualityProfileId,
            'rootFolderPath' => $rootFolderPath,
            'minimumAvailability' => $payload['minimumAvailability'] ?? 'released',
            'monitored' => true,
            'addOptions' => [
                'searchForMovie' => $payload['searchForMovie'] ?? true,
            ],
        ];

        return $this->safeCall(
            fn () => $this->client()->post('/movie', $body)->throw()->json(),
            'add movie'
        );
    }

    /**
     * @return array{exists: bool, id?: int}
     */
    public function checkExists(int $externalId): array
    {
        $response = $this->client()->get('/movie', ['tmdbId' => $externalId]);

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
        $response = $this->client()->get('/release', ['movieId' => $contentId]);

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
            ->sortByDesc('approved')
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
                        'movieId' => $payload['movieId'] ?? null,
                    ])
                    ->throw();

                return true;
            },
            'download release'
        );
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function triggerAutomaticSearch(int $contentId): array
    {
        return $this->safeCall(
            function () use ($contentId) {
                $this->client()
                    ->post('/command', ['name' => 'MoviesSearch', 'movieIds' => [$contentId]])
                    ->throw();

                return true;
            },
            'trigger automatic search'
        );
    }

    /**
     * @return array<int, bool> tmdbId => isDownloaded
     */
    public function fetchLibraryTmdbIds(): array
    {
        $response = $this->client()->get('/movie');

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->filter(fn ($m) => ! empty($m['tmdbId']))
            ->mapWithKeys(fn ($m) => [(int) $m['tmdbId'] => ($m['hasFile'] ?? false) === true])
            ->all();
    }

    public function supportsEpisodes(): bool
    {
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchQueue(): array
    {
        $response = $this->queueClient()->get('/queue', ['includeMovie' => 'true']);

        if (! $response->successful()) {
            return [];
        }

        return $this->parseQueueRecords($response->json()['records'] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    public function parseQueueRecords(array $records): array
    {
        return collect($records)
            ->map(function ($item) {
                $size = (int) ($item['size'] ?? 0);
                $sizeLeft = (int) ($item['sizeleft'] ?? 0);
                $progress = $size > 0 ? (int) round((1 - ($sizeLeft / $size)) * 100) : 0;

                $movie = $item['movie'] ?? null;

                return [
                    'id' => $item['id'] ?? null,
                    'downloadId' => $item['downloadId'] ?? null,
                    'title' => $movie['title'] ?? $item['title'] ?? 'Unknown',
                    'status' => $item['status'] ?? 'unknown',
                    'progress' => max(0, min(100, $progress)),
                    'size' => $size,
                    'sizeLeft' => $sizeLeft,
                    'timeLeft' => $item['timeleft'] ?? null,
                    'quality' => $item['quality']['quality']['name'] ?? null,
                    'protocol' => $item['protocol'] ?? null,
                    'indexer' => $item['indexer'] ?? null,
                    'episode' => null,
                    'trackedDownloadState' => $item['trackedDownloadState'] ?? null,
                ];
            })
            ->values()
            ->all();
    }
}
