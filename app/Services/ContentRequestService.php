<?php

namespace App\Services;

use App\Livewire\ArrQueueMonitor;
use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Models\MediaRequest;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Services\Arr\ArrService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ContentRequestService
{
    /** @return Collection<int, ArrIntegration> */
    public function integrations(Playlist $playlist): Collection
    {
        return ArrIntegration::query()
            ->where('user_id', $playlist->user_id)
            ->enabled()
            ->guestEnabled()
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{
     *     results: array<int, array<string, mixed>>,
     *     searched_providers: int,
     *     unavailable_providers: int
     * }
     */
    public function search(Playlist $playlist, string $term, ?string $type = null): array
    {
        $results = [];
        $searchedProviders = 0;
        $unavailableProviders = 0;

        foreach ($this->integrations($playlist) as $integration) {
            $mediaType = $integration->isRadarr() ? 'movie' : 'series';
            if ($type !== null && $type !== $mediaType) {
                continue;
            }

            $searchedProviders++;

            try {
                $items = ArrService::make($integration)->search($term);
            } catch (Throwable $throwable) {
                $unavailableProviders++;
                Log::warning('Content request search failed', [
                    'integration_id' => $integration->id,
                    'error' => $throwable->getMessage(),
                ]);

                continue;
            }

            foreach ($items as $item) {
                $externalId = $integration->isRadarr()
                    ? ($item['tmdbId'] ?? null)
                    : ($item['tvdbId'] ?? null);

                if (! $externalId) {
                    continue;
                }

                $resultKey = $mediaType.':'.$externalId;
                $results[$resultKey] ??= [
                    'type' => $mediaType,
                    'external_id' => (string) $externalId,
                    'integration_id' => $integration->id,
                    'integration_name' => $integration->name,
                    'title' => $item['title'] ?? 'Unknown',
                    'year' => $item['year'] ?? null,
                    'overview' => $item['overview'] ?? null,
                    'poster' => $item['poster'] ?? null,
                    'fanart' => $item['fanart'] ?? null,
                    'genres' => $item['genres'] ?? [],
                    'rating' => $item['rating'] ?? null,
                    'seasons' => $item['seasons'] ?? [],
                    'already_available' => (bool) ($item['existsInLibrary'] ?? false),
                ];
            }
        }

        return [
            'results' => array_values($results),
            'searched_providers' => $searchedProviders,
            'unavailable_providers' => $unavailableProviders,
        ];
    }

    /**
     * @return array{ok: bool, code?: string, error?: string, status?: string, request?: array<string, mixed>}
     */
    public function submit(
        Playlist $playlist,
        PlaylistAuth $playlistAuth,
        string $type,
        int $integrationId,
        int $externalId,
        ?array $selectedSeasons = null,
    ): array {
        $integration = $this->integrations($playlist)->firstWhere('id', $integrationId);
        $expectedType = $integration?->isRadarr() ? 'movie' : 'series';

        if (! $integration || $type !== $expectedType) {
            return ['ok' => false, 'code' => 'invalid_integration', 'error' => 'The selected integration is not available.'];
        }

        if (MediaRequest::query()
            ->where('playlist_auth_id', $playlistAuth->id)
            ->where('arr_integration_id', $integration->id)
            ->where('external_id', (string) $externalId)
            ->where('request_type', $type)
            ->whereIn('status', ['pending', 'approved'])
            ->exists()) {
            return ['ok' => false, 'code' => 'already_requested', 'error' => 'This title has already been requested.'];
        }

        $service = ArrService::make($integration);

        try {
            if ($service->checkExists($externalId)['exists']) {
                return ['ok' => false, 'code' => 'already_available', 'error' => 'This title is already available.'];
            }

            $lookupTerm = ($type === 'movie' ? 'tmdb:' : 'tvdb:').$externalId;
            $externalKey = $type === 'movie' ? 'tmdbId' : 'tvdbId';
            $item = collect($service->search($lookupTerm))->first(
                fn (array $result): bool => (int) ($result[$externalKey] ?? 0) === $externalId,
            );
        } catch (Throwable $throwable) {
            Log::warning('Content request lookup failed', [
                'integration_id' => $integration->id,
                'error' => $throwable->getMessage(),
            ]);

            return ['ok' => false, 'code' => 'provider_unavailable', 'error' => 'The request provider is temporarily unavailable.'];
        }

        if (! $item) {
            return ['ok' => false, 'code' => 'not_found', 'error' => 'The requested title was not found.'];
        }

        $payload = [
            $externalKey => $externalId,
            'title' => $item['title'] ?? null,
            'titleSlug' => $item['titleSlug'] ?? null,
            'images' => $item['images'] ?? [],
            'qualityProfileId' => $integration->quality_profile_id,
            'rootFolderPath' => $integration->root_folder_path,
            $type === 'movie' ? 'searchForMovie' : 'searchForMissingEpisodes' => true,
        ];

        if ($type === 'series' && $selectedSeasons !== null) {
            $availableSeasons = collect($item['seasons'] ?? [])
                ->pluck('seasonNumber')
                ->map(fn (mixed $season): int => (int) $season)
                ->all();

            if (array_diff($selectedSeasons, $availableSeasons) !== []) {
                return ['ok' => false, 'code' => 'invalid_seasons', 'error' => 'One or more selected seasons are unavailable.'];
            }

            $payload['seasons'] = collect($item['seasons'])
                ->map(fn (array $season): array => [
                    'seasonNumber' => (int) $season['seasonNumber'],
                    'monitored' => in_array((int) $season['seasonNumber'], $selectedSeasons, true),
                ])
                ->values()
                ->all();
        }

        if (! $playlistAuth->auto_approve_requests) {
            $mediaRequest = MediaRequest::create([
                'playlist_auth_id' => $playlistAuth->id,
                'arr_integration_id' => $integration->id,
                'title' => $item['title'] ?? 'Unknown',
                'external_id' => (string) $externalId,
                'request_type' => $type,
                'payload' => $payload,
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            return [
                'ok' => true,
                'status' => 'pending_approval',
                'request' => $this->formatRequest($mediaRequest),
            ];
        }

        $result = $service->add($payload);
        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'code' => 'submission_failed',
                'error' => 'The request provider could not accept this title.',
            ];
        }

        $mediaRequest = MediaRequest::create([
            'playlist_auth_id' => $playlistAuth->id,
            'arr_integration_id' => $integration->id,
            'title' => $item['title'] ?? 'Unknown',
            'external_id' => (string) $externalId,
            'request_type' => $type,
            'payload' => $payload,
            'status' => 'approved',
            'requested_at' => now(),
            'reviewed_at' => now(),
        ]);

        return [
            'ok' => true,
            'status' => 'approved',
            'request' => $this->formatRequest($mediaRequest),
        ];
    }

    /** @return array{requests: array<int, array<string, mixed>>, total: int} */
    public function history(PlaylistAuth $playlistAuth, int $page, int $perPage): array
    {
        $query = MediaRequest::query()
            ->where('playlist_auth_id', $playlistAuth->id)
            ->with('arrIntegration:id,name');

        $total = (clone $query)->count();
        $requests = $query->orderByDesc('requested_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (MediaRequest $request): array => $this->formatRequest($request))
            ->all();

        return ['requests' => $requests, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function status(PlaylistAuth $playlistAuth, int $requestId): ?array
    {
        $mediaRequest = MediaRequest::query()
            ->where('id', $requestId)
            ->where('playlist_auth_id', $playlistAuth->id)
            ->with('arrIntegration')
            ->first();

        if (! $mediaRequest) {
            return null;
        }

        $formatted = $this->formatRequest($mediaRequest);
        $integration = $mediaRequest->arrIntegration;
        if ($mediaRequest->status !== 'approved'
            || ! $integration?->enabled
            || ! $integration->guest_enabled) {
            return $formatted;
        }

        try {
            $queueItem = collect(ArrService::make($integration)->fetchQueue())
                ->first(fn (array $item): bool => mb_strtolower(trim($item['title']))
                    === mb_strtolower(trim($mediaRequest->title)));
        } catch (Throwable $throwable) {
            Log::warning('Content request status lookup failed', [
                'integration_id' => $integration->id,
                'error' => $throwable->getMessage(),
            ]);

            $queueItem = null;
        }

        if ($queueItem) {
            $status = ArrQueueMonitor::resolveStatus(
                $queueItem['status'],
                $queueItem['trackedDownloadState'] ?? null,
            );
            $progress = $queueItem['progress'];
            $quality = $queueItem['quality'] ?? null;
            $protocol = $queueItem['protocol'] ?? null;
            $size = $queueItem['size'];
            $timeLeft = $queueItem['timeLeft'] ?? null;
        } else {
            $eventQuery = ArrQueueEvent::query()
                ->where('arr_integration_id', $integration->id)
                ->where('last_event_at', '>=', $mediaRequest->requested_at);

            if ($mediaRequest->external_id !== null) {
                $eventQuery->where('external_id', $mediaRequest->external_id);
            } else {
                $eventQuery->where('title', $mediaRequest->title);
            }

            $event = $eventQuery->orderByDesc('last_event_at')->first();
            if (! $event) {
                return $formatted;
            }

            $status = $event->status;
            $progress = $event->progress;
            $quality = $event->quality;
            $protocol = null;
            $size = $event->size;
            $timeLeft = null;
        }

        if (in_array($status, ['completed', 'imported'], true)) {
            $status = 'completed';
            $mediaRequest->update(['status' => $status]);
            $formatted = $this->formatRequest($mediaRequest);
        }

        return array_merge($formatted, [
            'status' => $status,
            'progress' => $progress,
            'quality' => $quality,
            'protocol' => $protocol,
            'size' => $size,
            'time_left' => $timeLeft,
        ]);
    }

    /** @return array{ok: bool, code?: string} */
    public function dismiss(PlaylistAuth $playlistAuth, int $requestId): array
    {
        $mediaRequest = MediaRequest::query()
            ->where('id', $requestId)
            ->where('playlist_auth_id', $playlistAuth->id)
            ->first();

        if (! $mediaRequest) {
            return ['ok' => false, 'code' => 'request_not_found'];
        }

        if (! in_array($mediaRequest->status, ['completed', 'rejected'], true)) {
            return ['ok' => false, 'code' => 'request_not_dismissible'];
        }

        $mediaRequest->delete();

        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    private function formatRequest(MediaRequest $request): array
    {
        return [
            'id' => $request->id,
            'type' => $request->request_type,
            'external_id' => $request->external_id,
            'title' => $request->title,
            'status' => $request->status === 'pending' ? 'pending_approval' : $request->status,
            'integration_id' => $request->arr_integration_id,
            'integration_name' => $request->arrIntegration?->name,
            'season_number' => $request->season_number,
            'episode_number' => $request->episode_number,
            'requested_at' => $request->requested_at?->toIso8601String(),
            'can_dismiss' => in_array($request->status, ['completed', 'rejected'], true),
        ];
    }
}
