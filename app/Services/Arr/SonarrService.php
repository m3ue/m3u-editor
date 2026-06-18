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
            ->map(function ($item) {
                $fanart = collect($item['images'] ?? [])->firstWhere('coverType', 'fanart');
                $ratings = $item['ratings'] ?? [];
                $rating = null;
                if (! empty($ratings['value'])) {
                    $rating = [
                        'value' => round((float) $ratings['value'], 1),
                        'votes' => (int) ($ratings['votes'] ?? 0),
                        'source' => 'tvdb',
                    ];
                }

                return [
                    'tvdbId' => $item['tvdbId'] ?? null,
                    'title' => $item['title'] ?? 'Unknown',
                    'titleSlug' => $item['titleSlug'] ?? null,
                    'year' => $item['year'] ?? null,
                    'overview' => $item['overview'] ?? null,
                    'poster' => $item['remotePoster'] ?? null,
                    'fanart' => $fanart ? ($fanart['remoteUrl'] ?? null) : null,
                    'seasons' => $item['seasons'] ?? [],
                    'status' => $item['status'] ?? null,
                    'network' => $item['network'] ?? null,
                    'genres' => $item['genres'] ?? [],
                    'runtime' => $item['runtime'] ?? null,
                    'certification' => $item['certification'] ?? null,
                    'rating' => $rating,
                    'existsInLibrary' => isset($item['id']),
                    'libraryId' => isset($item['id']) ? (int) $item['id'] : null,
                    'episodeFileCount' => (int) ($item['statistics']['episodeFileCount'] ?? 0),
                    'totalEpisodeCount' => (int) ($item['statistics']['totalEpisodeCount'] ?? 0),
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
                'error' => __('Sonarr integration ":name" is missing a quality profile or root folder. Please configure it in Settings → Integrations.', [
                    'name' => $this->integration->name,
                ]),
            ];
        }

        $body = [
            'tvdbId' => $payload['tvdbId'] ?? null,
            'title' => $payload['title'] ?? null,
            'titleSlug' => $payload['titleSlug'] ?? null,
            'qualityProfileId' => $qualityProfileId,
            'rootFolderPath' => $rootFolderPath,
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
            ->sortByDesc('approved')
            ->values()
            ->all();
    }

    /**
     * Fetch per-episode hasFile status for a series already in the Sonarr library.
     *
     * @return array<int, array<int, bool>> seasonNumber => [episodeNumber => hasFile]
     */
    public function fetchEpisodes(int $seriesId): array
    {
        $response = $this->client()->get('/episode', ['seriesId' => $seriesId]);

        if (! $response->successful()) {
            return [];
        }

        $status = [];
        foreach ($response->json() ?? [] as $episode) {
            $season = (int) ($episode['seasonNumber'] ?? 0);
            $epNum = (int) ($episode['episodeNumber'] ?? 0);
            $status[$season][$epNum] = ($episode['hasFile'] ?? false) === true;
        }

        return $status;
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
     * Request a single episode. Adds the series (all seasons unmonitored) if it is
     * not yet in the library, then monitors and searches the specific episode.
     *
     * @param  array{qualityProfileId?: int, rootFolderPath?: string}  $defaults
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    public function requestEpisode(int $tvdbId, int $seasonNumber, int $episodeNumber, array $defaults = []): array
    {
        return $this->safeCall(function () use ($tvdbId, $seasonNumber, $episodeNumber, $defaults) {
            // Resolve the Sonarr series ID, adding the series if necessary
            $lookup = $this->client()
                ->get('/series/lookup', ['term' => 'tvdb:'.$tvdbId])
                ->throw()
                ->json();

            $series = $lookup[0] ?? null;

            if (! $series) {
                throw new \RuntimeException('Series not found via TVDB lookup.');
            }

            $sonarrId = $series['id'] ?? null;

            if (! $sonarrId) {
                // Add the series with every season unmonitored so nothing bulk-downloads
                $seasons = collect($series['seasons'] ?? [])
                    ->map(fn ($s) => ['seasonNumber' => (int) $s['seasonNumber'], 'monitored' => false])
                    ->all();

                $added = $this->client()
                    ->post('/series', [
                        'tvdbId' => $tvdbId,
                        'title' => $series['title'],
                        'titleSlug' => $series['titleSlug'],
                        'qualityProfileId' => $defaults['qualityProfileId'] ?? $this->integration->quality_profile_id,
                        'rootFolderPath' => $defaults['rootFolderPath'] ?? $this->integration->root_folder_path,
                        'monitored' => true,
                        'seasons' => $seasons,
                        'addOptions' => ['searchForMissingEpisodes' => false],
                    ])
                    ->throw()
                    ->json();

                $sonarrId = $added['id'];
            }

            // Find the target episode by season + episode number
            $episodes = $this->client()
                ->get('/episode', ['seriesId' => $sonarrId, 'seasonNumber' => $seasonNumber])
                ->throw()
                ->json();

            $episode = collect($episodes ?? [])->firstWhere('episodeNumber', $episodeNumber);

            if (! $episode) {
                throw new \RuntimeException("Episode S{$seasonNumber}E{$episodeNumber} not found in Sonarr.");
            }

            $episodeId = (int) $episode['id'];

            // Monitor the episode then trigger an immediate search
            $this->client()
                ->put('/episode/monitor', ['episodeIds' => [$episodeId], 'monitored' => true])
                ->throw();

            $this->client()
                ->post('/command', ['name' => 'EpisodeSearch', 'episodeIds' => [$episodeId]])
                ->throw();

            return $episodeId;
        }, "request episode S{$seasonNumber}E{$episodeNumber}");
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function triggerAutomaticSearch(int $contentId): array
    {
        return $this->safeCall(
            function () use ($contentId) {
                $this->client()
                    ->post('/command', ['name' => 'SeriesSearch', 'seriesId' => $contentId])
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
        $response = $this->client()->get('/series');

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->filter(fn ($s) => ! empty($s['tmdbId']))
            ->mapWithKeys(fn ($s) => [(int) $s['tmdbId'] => (int) ($s['statistics']['episodeFileCount'] ?? 0) > 0])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchQueue(): array
    {
        $response = $this->queueClient()->get('/queue', ['includeSeries' => 'true']);

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
                    'downloadId' => $item['downloadId'] ?? null,
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
