<?php

namespace App\Livewire;

use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Models\MediaRequest;
use App\Services\Arr\ArrService;
use App\Services\Arr\SonarrService;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Livewire\Component;

class ArrQueueMonitor extends Component
{
    /**
     * Keyed by integration ID.
     *
     * @var array<int, array{integration: array<string, mixed>, items: array<int, array<string, mixed>>, error: bool}>
     */
    public array $queues = [];

    /** @var array<string, string> */
    protected $listeners = ['refreshArrQueue' => 'loadQueues'];

    public function mount(): void
    {
        $this->loadQueues();
    }

    /**
     * Listen for real-time webhook-triggered broadcasts.
     * Using getListeners() because the channel name contains a runtime value.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:arr-queue.'.auth()->id().',.queue.updated' => 'refresh',
        ];
    }

    public function refresh(): void
    {
        $this->loadQueues();
    }

    public function loadQueues(): void
    {
        $integrations = ArrIntegration::query()
            ->where('user_id', auth()->id())
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->values(); // 0-indexed so pool responses map by position

        if ($integrations->isEmpty()) {
            $this->dispatch('queue-status', count: 0);

            return;
        }

        // Pre-load all pending MediaRequests for all integrations in one query to avoid N+1.
        $integrationIds = $integrations->pluck('id')->all();
        $pendingRequestsByIntegration = MediaRequest::query()
            ->whereIn('arr_integration_id', $integrationIds)
            ->where('status', 'pending')
            ->with('playlistAuth')
            ->orderBy('requested_at')
            ->get()
            ->groupBy('arr_integration_id');

        // Fire all queue HTTP requests in parallel instead of serially.
        $services = $integrations->map(fn ($i) => ArrService::make($i));

        $queueResponses = Http::pool(function (Pool $pool) use ($integrations) {
            return $integrations->map(function ($integration) use ($pool) {
                $params = $integration->isSonarr()
                    ? ['includeSeries' => 'true', 'includeEpisode' => 'true']
                    : ['includeMovie' => 'true'];

                return $pool
                    ->baseUrl($integration->base_url.'/api/v3')
                    ->timeout(5)
                    ->acceptJson()
                    ->withHeaders(['X-Api-Key' => $integration->api_key])
                    ->get('/queue', $params);
            })->all();
        });

        foreach ($integrations as $index => $integration) {
            $queueResponse = $queueResponses[$index];

            // Live items from the Arr service (actively downloading — have progress).
            $liveItems = collect();
            $liveError = false;

            if ($queueResponse instanceof \Throwable || ! $queueResponse->successful()) {
                $liveError = true;
            } else {
                $liveItems = collect($services[$index]->parseQueueRecords($queueResponse->json()['records'] ?? []));
            }

            $liveByDownloadId = $liveItems->keyBy('downloadId')->filter();
            $currentLiveDownloadIds = $liveItems->pluck('downloadId')->filter()->flip();

            // Load DB events first so we know which downloadIds are already webhook-tracked,
            // and so previously persisted snapshots are included without a second query.
            $localEvents = ArrQueueEvent::query()
                ->where('arr_integration_id', $integration->id)
                ->orderByDesc('last_event_at')
                ->get();

            // downloadIds already covered by a real webhook event (not just a completed snapshot).
            $webhookTrackedDownloadIds = $localEvents
                ->where('event_type', '!=', 'CompletedSnapshot')
                ->pluck('download_id')
                ->filter()
                ->flip()
                ->all();

            // Snapshot orphan live items that vanished since the last poll.
            // Persist to the DB so they survive page refreshes.
            $prevOrphans = collect($this->queues[$integration->id]['items'] ?? [])
                ->where('source', 'live')
                ->filter(fn ($i) => ! empty($i['downloadId']));

            foreach ($prevOrphans as $prevItem) {
                $downloadId = $prevItem['downloadId'];

                // Skip if still live or already tracked by a webhook event.
                if (isset($currentLiveDownloadIds[$downloadId]) || isset($webhookTrackedDownloadIds[$downloadId])) {
                    continue;
                }

                // Skip if a snapshot record already exists in the loaded collection.
                $alreadySnapshotted = $localEvents->contains(
                    fn ($e) => $e->download_id === $downloadId && $e->event_type === 'CompletedSnapshot'
                );

                if (! $alreadySnapshotted) {
                    $newEvent = ArrQueueEvent::create([
                        'arr_integration_id' => $integration->id,
                        'user_id' => auth()->id(),
                        'download_id' => $downloadId,
                        'title' => $prevItem['title'],
                        'event_type' => 'CompletedSnapshot',
                        'status' => 'completed',
                        'quality' => $prevItem['quality'] ?? null,
                        'size' => $prevItem['size'] ?? 0,
                        'progress' => 100,
                        'last_event_at' => now(),
                    ]);
                    $localEvents->push($newEvent);
                }
            }

            // Build merged item list: local events take precedence (richer status vocabulary).
            // For items that are actively downloading, enrich with live progress.
            $seenDownloadIds = [];
            $items = $localEvents->map(function (ArrQueueEvent $event) use ($liveByDownloadId, &$seenDownloadIds) {
                $live = $event->download_id ? $liveByDownloadId->get($event->download_id) : null;

                if ($event->download_id) {
                    $seenDownloadIds[] = $event->download_id;
                }

                $trackedState = $live ? ($live['trackedDownloadState'] ?? null) : null;
                $effectiveStatus = self::resolveStatus(
                    $live ? $live['status'] : $event->status,
                    $trackedState
                );

                $canDismiss = in_array($effectiveStatus, ['imported', 'completed', 'failed', 'monitored', 'manual_required']);
                $isSnapshot = $event->event_type === 'CompletedSnapshot';

                return [
                    'title' => $event->title,
                    'status' => $effectiveStatus,
                    'progress' => $live ? $live['progress'] : $event->progress,
                    'quality' => $event->quality ?? ($live ? ($live['quality'] ?? null) : null),
                    'protocol' => $live ? ($live['protocol'] ?? null) : null,
                    'indexer' => $live ? ($live['indexer'] ?? null) : null,
                    'episode' => $live ? ($live['episode'] ?? null) : null,
                    'size' => $live ? (int) $live['size'] : (int) $event->size,
                    'timeLeft' => $live ? ($live['timeLeft'] ?? null) : null,
                    'event_type' => $event->event_type,
                    'last_event_at' => $event->last_event_at?->toIso8601String(),
                    'formattedSize' => self::formatBytes($live ? (int) $live['size'] : (int) $event->size),
                    'source' => $isSnapshot ? 'snapshot' : 'webhook',
                    'event_db_id' => $event->id,
                    'dismiss_source' => 'event',
                    'dismiss_key' => (string) $event->id,
                    'can_dismiss' => $canDismiss,
                ];
            });

            // Include live items not matched by any local event (e.g., downloads started before
            // webhooks were configured, or responses without a downloadId).
            $orphanLive = $liveItems
                ->filter(fn ($item) => ! ($item['downloadId'] && in_array($item['downloadId'], $seenDownloadIds)))
                ->map(fn ($item) => [
                    'title' => $item['title'],
                    'status' => self::resolveStatus($item['status'], $item['trackedDownloadState'] ?? null),
                    'progress' => $item['progress'],
                    'quality' => $item['quality'] ?? null,
                    'protocol' => $item['protocol'] ?? null,
                    'indexer' => $item['indexer'] ?? null,
                    'episode' => $item['episode'] ?? null,
                    'downloadId' => $item['downloadId'] ?? null,
                    'size' => (int) $item['size'],
                    'timeLeft' => $item['timeLeft'] ?? null,
                    'event_type' => 'live',
                    'last_event_at' => null,
                    'formattedSize' => self::formatBytes((int) $item['size']),
                    'source' => 'live',
                    'can_dismiss' => false,
                ]);

            // Pending media requests awaiting admin approval for this integration.
            $pendingRequests = ($pendingRequestsByIntegration[$integration->id] ?? collect())
                ->map(fn (MediaRequest $mr) => [
                    'title' => $mr->title,
                    'status' => 'pending_approval',
                    'progress' => 0,
                    'quality' => null,
                    'protocol' => null,
                    'indexer' => null,
                    'episode' => $mr->request_type === 'episode'
                        ? 'S'.str_pad((string) $mr->season_number, 2, '0', STR_PAD_LEFT).'E'.str_pad((string) $mr->episode_number, 2, '0', STR_PAD_LEFT)
                        : null,
                    'size' => 0,
                    'timeLeft' => null,
                    'event_type' => 'media_request',
                    'last_event_at' => $mr->requested_at?->toIso8601String(),
                    'formattedSize' => '–',
                    'source' => 'media_request',
                    'media_request_id' => $mr->id,
                    'requested_by' => $mr->playlistAuth?->name ?? __('Guest'),
                    'can_dismiss' => false,
                ]);

            $this->queues[$integration->id] = [
                'integration' => [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'type' => $integration->type,
                ],
                'items' => $items->concat($orphanLive)->concat($pendingRequests)->values()->all(),
                'error' => $liveError && $localEvents->isEmpty(),
            ];
        }

        $this->dispatch('queue-status', count: $this->totalCount);
    }

    /**
     * Dismiss a queue item from the display by deleting its ArrQueueEvent record.
     * Both webhook events and completed snapshots are stored in the DB.
     */
    public function dismissItem(string $source, string $key): void
    {
        ArrQueueEvent::where('id', (int) $key)
            ->where('user_id', auth()->id())
            ->delete();

        $this->loadQueues();
    }

    public function approveRequest(int $mediaRequestId): void
    {
        $request = MediaRequest::whereHas(
            'arrIntegration',
            fn ($q) => $q->where('user_id', auth()->id())
        )->find($mediaRequestId);

        if (! $request || ! $request->isPending()) {
            return;
        }

        $integration = ArrIntegration::where('id', $request->arr_integration_id)
            ->where('user_id', auth()->id())
            ->first();

        if (! $integration) {
            return;
        }

        $service = ArrService::make($integration);
        $payload = $request->payload;

        if ($request->request_type === 'episode') {
            /** @var SonarrService $service */
            $result = $service->requestEpisode(
                (int) ($payload['tvdbId'] ?? 0),
                (int) ($payload['seasonNumber'] ?? 0),
                (int) ($payload['episodeNumber'] ?? 0),
                [
                    'qualityProfileId' => $payload['qualityProfileId'] ?? null,
                    'rootFolderPath' => $payload['rootFolderPath'] ?? null,
                ]
            );
            $ok = ($result['ok'] ?? false) || ($result['queued'] ?? false);
        } else {
            $result = $service->add($payload);
            $ok = $result['ok'] ?? false;
        }

        if ($ok) {
            $request->update([
                'status' => 'approved',
                'reviewed_at' => now(),
                'reviewed_by_user_id' => auth()->id(),
            ]);
            $request->broadcastStatus();

            Notification::make()
                ->success()
                ->title(__('Request Approved'))
                ->body(__(':title has been sent to :server.', [
                    'title' => $request->title,
                    'server' => $integration->name,
                ]))
                ->send();
        } else {
            Notification::make()
                ->danger()
                ->title(__('Approval Failed'))
                ->body($result['error'] ?? 'Unknown error')
                ->send();
        }

        $this->loadQueues();
    }

    public function rejectRequest(int $mediaRequestId): void
    {
        $request = MediaRequest::whereHas(
            'arrIntegration',
            fn ($q) => $q->where('user_id', auth()->id())
        )->find($mediaRequestId);

        if (! $request || ! $request->isPending()) {
            return;
        }

        $integration = ArrIntegration::where('id', $request->arr_integration_id)
            ->where('user_id', auth()->id())
            ->first();

        if (! $integration) {
            return;
        }

        $request->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
            'reviewed_by_user_id' => auth()->id(),
        ]);
        $request->broadcastStatus();

        Notification::make()
            ->warning()
            ->title(__('Request Rejected'))
            ->body(__('":title" request has been rejected.', ['title' => $request->title]))
            ->send();

        $this->loadQueues();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSonarrQueuesProperty(): array
    {
        return collect($this->queues)
            ->filter(fn ($q) => $q['integration']['type'] === 'sonarr')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRadarrQueuesProperty(): array
    {
        return collect($this->queues)
            ->filter(fn ($q) => $q['integration']['type'] === 'radarr')
            ->values()
            ->all();
    }

    public function getTotalCountProperty(): int
    {
        return collect($this->queues)->sum(fn ($q) => count($q['items']));
    }

    /**
     * Resolve the display status from the raw Arr status + trackedDownloadState.
     * trackedDownloadState is more precise once a download completes.
     */
    public static function resolveStatus(string $status, ?string $trackedDownloadState): string
    {
        return match ($trackedDownloadState) {
            'importPending' => 'import_pending',
            'importing' => 'importing',
            'imported' => 'imported',
            'failedPending' => 'failed',
            default => $status,
        };
    }

    /**
     * @return array{color: string, label: string}
     */
    public static function statusBadge(string $status): array
    {
        return match (strtolower($status)) {
            'pending_approval' => ['color' => 'warning', 'label' => 'Pending Approval'],
            'approved' => ['color' => 'success', 'label' => 'Approved'],
            'rejected' => ['color' => 'danger', 'label' => 'Rejected'],
            'monitored' => ['color' => 'gray', 'label' => 'Monitored'],
            'grabbing' => ['color' => 'warning', 'label' => 'Grabbing'],
            'downloading' => ['color' => 'primary', 'label' => 'Downloading'],
            'import_pending' => ['color' => 'warning', 'label' => 'Import Pending'],
            'importing' => ['color' => 'primary', 'label' => 'Importing'],
            'imported' => ['color' => 'success', 'label' => 'Imported'],
            'manual_required' => ['color' => 'danger', 'label' => 'Manual Required'],
            'queued' => ['color' => 'warning', 'label' => 'Queued'],
            'paused' => ['color' => 'gray', 'label' => 'Paused'],
            'completed' => ['color' => 'success', 'label' => 'Completed'],
            'failed', 'error' => ['color' => 'danger', 'label' => 'Failed'],
            default => ['color' => 'gray', 'label' => ucfirst($status)],
        };
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '–';
        }

        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 2).' GB';
        }

        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 2).' MB';
        }

        return number_format($bytes / 1_024, 2).' KB';
    }

    public function render(): View
    {
        return view('livewire.arr-queue-monitor');
    }
}
