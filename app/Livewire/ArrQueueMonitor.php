<?php

namespace App\Livewire;

use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Services\Arr\ArrService;
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
            ->get();

        foreach ($integrations as $integration) {
            // Live items from the Arr service (actively downloading — have progress).
            $liveItems = collect();
            $liveError = false;
            try {
                $liveItems = collect(ArrService::make($integration)->fetchQueue());
            } catch (\Exception) {
                $liveError = true;
            }

            $liveByDownloadId = $liveItems->keyBy('downloadId')->filter();

            // Webhook-sourced local events for the last 48 h (imported events age out after that).
            $localEvents = ArrQueueEvent::query()
                ->where('arr_integration_id', $integration->id)
                ->where(function ($q) {
                    $q->where('status', '!=', 'imported')
                        ->orWhere('last_event_at', '>', now()->subHours(24));
                })
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

                return [
                    'title' => $event->title,
                    'status' => $live ? $live['status'] : $event->status,
                    'progress' => $live ? $live['progress'] : $event->progress,
                    'quality' => $event->quality,
                    'size' => $live ? (int) $live['size'] : (int) $event->size,
                    'timeLeft' => $live ? ($live['timeLeft'] ?? null) : null,
                    'event_type' => $event->event_type,
                    'last_event_at' => $event->last_event_at?->toIso8601String(),
                    'formattedSize' => self::formatBytes($live ? (int) $live['size'] : (int) $event->size),
                    'source' => 'webhook',
                ];
            });

            // Include live items not matched by any local event (e.g., downloads started before
            // webhooks were configured, or responses without a downloadId).
            $orphanLive = $liveItems
                ->filter(fn ($item) => ! ($item['downloadId'] && in_array($item['downloadId'], $seenDownloadIds)))
                ->map(fn ($item) => [
                    'title' => $item['title'],
                    'status' => $item['status'],
                    'progress' => $item['progress'],
                    'quality' => null,
                    'size' => (int) $item['size'],
                    'timeLeft' => $item['timeLeft'] ?? null,
                    'event_type' => 'live',
                    'last_event_at' => null,
                    'formattedSize' => self::formatBytes((int) $item['size']),
                    'source' => 'live',
                ]);

            $this->queues[$integration->id] = [
                'integration' => [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'type' => $integration->type,
                ],
                'items' => $items->concat($orphanLive)->values()->all(),
                'error' => $liveError && $localEvents->isEmpty(),
            ];
        }

        $this->dispatch('queue-status', count: $this->totalCount);
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
     * @return array{color: string, label: string}
     */
    public static function statusBadge(string $status): array
    {
        return match (strtolower($status)) {
            'monitored' => ['color' => 'gray', 'label' => 'Monitored'],
            'grabbing' => ['color' => 'warning', 'label' => 'Grabbing'],
            'downloading' => ['color' => 'primary', 'label' => 'Downloading'],
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
