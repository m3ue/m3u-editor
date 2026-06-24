<?php

namespace App\Livewire;

use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Services\Arr\ArrService;
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

    /**
     * Completed orphan items (no webhook event) captured between polls.
     * Keyed by "{integrationId}_{downloadId}". Persists until dismissed.
     * Capped at MAX_SNAPSHOTS_PER_INTEGRATION per integration to prevent unbounded growth.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $completedSnapshots = [];

    protected const MAX_SNAPSHOTS_PER_INTEGRATION = 25;

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

            // Snapshot orphan live items that vanished since the last poll.
            // This lets us show "Completed" even when the item leaves the Arr queue.
            $prevOrphans = collect($this->queues[$integration->id]['items'] ?? [])
                ->where('source', 'live')
                ->filter(fn ($i) => ! empty($i['downloadId']));

            foreach ($prevOrphans as $prevItem) {
                $downloadId = $prevItem['downloadId'];
                $key = "{$integration->id}_{$downloadId}";

                if (! isset($currentLiveDownloadIds[$downloadId]) && ! isset($this->completedSnapshots[$key])) {
                    $this->completedSnapshots[$key] = array_merge($prevItem, [
                        'status' => 'completed',
                        'progress' => 100,
                        'timeLeft' => null,
                        'integration_id' => $integration->id,
                        'dismiss_source' => 'snapshot',
                        'dismiss_key' => $key,
                        'can_dismiss' => true,
                        'source' => 'snapshot',
                    ]);

                    // Trim oldest snapshots for this integration to enforce the cap.
                    $integrationSnapshots = array_filter(
                        $this->completedSnapshots,
                        fn ($s) => ($s['integration_id'] ?? null) === $integration->id
                    );

                    if (count($integrationSnapshots) > self::MAX_SNAPSHOTS_PER_INTEGRATION) {
                        unset($this->completedSnapshots[(string) array_key_first($integrationSnapshots)]);
                    }
                }
            }

            // Webhook-sourced local events — all statuses persist until manually dismissed.
            $localEvents = ArrQueueEvent::query()
                ->where('arr_integration_id', $integration->id)
                ->orderByDesc('last_event_at')
                ->get();

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
                    'source' => 'webhook',
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

            // Merge in completed snapshots that belong to this integration.
            $snapshotItems = collect($this->completedSnapshots)
                ->filter(fn ($s) => ($s['integration_id'] ?? null) === $integration->id)
                ->values();

            $this->queues[$integration->id] = [
                'integration' => [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'type' => $integration->type,
                ],
                'items' => $items->concat($orphanLive)->concat($snapshotItems)->values()->all(),
                'error' => $liveError && $localEvents->isEmpty(),
            ];
        }

        $this->dispatch('queue-status', count: $this->totalCount);
    }

    /**
     * Dismiss a queue item from the display.
     * For webhook events: delete the local ArrQueueEvent record.
     * For completed snapshots: remove from component state.
     */
    public function dismissItem(string $source, string $key): void
    {
        if ($source === 'event') {
            ArrQueueEvent::where('id', (int) $key)
                ->where('user_id', auth()->id())
                ->delete();
        } elseif ($source === 'snapshot') {
            unset($this->completedSnapshots[$key]);
        }

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
