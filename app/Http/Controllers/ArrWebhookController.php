<?php

namespace App\Http\Controllers;

use App\Events\ArrQueueUpdated;
use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ArrWebhookController extends Controller
{
    public function receive(ArrIntegration $integration, Request $request): Response
    {
        $eventType = $request->input('eventType', 'Unknown');

        if ($eventType === 'Test') {
            return response()->noContent();
        }

        $payload = $request->all();

        try {
            $this->processEvent($integration, $eventType, $payload);
        } catch (\Throwable $e) {
            Log::warning('ArrWebhook: failed to process event', [
                'integration_id' => $integration->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }

        ArrQueueUpdated::dispatch($integration->user_id);

        return response()->noContent();
    }

    private function processEvent(ArrIntegration $integration, string $eventType, array $payload): void
    {
        match ($eventType) {
            'MovieAdded', 'SeriesAdd' => $this->handleAdded($integration, $payload),
            'Grab' => $this->handleGrab($integration, $payload),
            'Download' => $this->handleDownload($integration, $payload),
            'ManualInteractionRequired' => $this->handleManualRequired($integration, $payload),
            default => Log::debug('ArrWebhook: unhandled event type', [
                'integration_id' => $integration->id,
                'event_type' => $eventType,
            ]),
        };
    }

    private function handleAdded(ArrIntegration $integration, array $payload): void
    {
        $title = $this->extractTitle($integration, $payload);
        $externalId = $this->extractExternalId($integration, $payload);

        if (! $title || ! $externalId) {
            return;
        }

        ArrQueueEvent::updateOrCreate(
            ['arr_integration_id' => $integration->id, 'external_id' => $externalId, 'download_id' => null],
            [
                'user_id' => $integration->user_id,
                'title' => $title,
                'event_type' => $integration->isRadarr() ? 'MovieAdded' : 'SeriesAdd',
                'status' => 'monitored',
                'quality' => null,
                'release_title' => null,
                'size' => 0,
                'progress' => 0,
                'last_event_at' => now(),
            ]
        );
    }

    private function handleGrab(ArrIntegration $integration, array $payload): void
    {
        $downloadId = $payload['downloadId'] ?? null;
        $title = $this->extractTitle($integration, $payload);
        $externalId = $this->extractExternalId($integration, $payload);
        $release = $payload['release'] ?? [];
        $quality = $release['quality']['quality']['name'] ?? $release['qualityVersion'] ?? null;
        $releaseTitle = $release['releaseTitle'] ?? null;
        $size = (int) ($release['size'] ?? 0);

        if (! $title) {
            return;
        }

        $attributes = [
            'arr_integration_id' => $integration->id,
            'download_id' => $downloadId,
        ];

        // If we have a prior "monitored" event for this external ID, update it to grabbing.
        if ($externalId) {
            ArrQueueEvent::where('arr_integration_id', $integration->id)
                ->where('external_id', $externalId)
                ->where('status', 'monitored')
                ->delete();
        }

        ArrQueueEvent::updateOrCreate($attributes, [
            'user_id' => $integration->user_id,
            'external_id' => $externalId,
            'title' => $title,
            'event_type' => 'Grab',
            'status' => 'grabbing',
            'quality' => $quality,
            'release_title' => $releaseTitle,
            'size' => $size,
            'progress' => 0,
            'last_event_at' => now(),
        ]);
    }

    private function handleDownload(ArrIntegration $integration, array $payload): void
    {
        $downloadId = $payload['downloadId'] ?? null;
        $title = $this->extractTitle($integration, $payload);
        $externalId = $this->extractExternalId($integration, $payload);

        if (! $title) {
            return;
        }

        $quality = null;
        if ($integration->isRadarr()) {
            $quality = $payload['movieFile']['quality']['quality']['name'] ?? null;
        } else {
            $quality = $payload['episodeFile']['quality']['quality']['name'] ?? null;
        }

        $attributes = $downloadId
            ? ['arr_integration_id' => $integration->id, 'download_id' => $downloadId]
            : ['arr_integration_id' => $integration->id, 'external_id' => $externalId];

        ArrQueueEvent::updateOrCreate($attributes, [
            'user_id' => $integration->user_id,
            'external_id' => $externalId,
            'title' => $title,
            'event_type' => 'Download',
            'status' => 'imported',
            'quality' => $quality,
            'size' => 0,
            'progress' => 100,
            'last_event_at' => now(),
        ]);
    }

    private function handleManualRequired(ArrIntegration $integration, array $payload): void
    {
        $downloadId = $payload['downloadId'] ?? null;
        $title = $this->extractTitle($integration, $payload);
        $externalId = $this->extractExternalId($integration, $payload);

        if (! $title) {
            return;
        }

        $attributes = $downloadId
            ? ['arr_integration_id' => $integration->id, 'download_id' => $downloadId]
            : ['arr_integration_id' => $integration->id, 'external_id' => $externalId];

        ArrQueueEvent::updateOrCreate($attributes, [
            'user_id' => $integration->user_id,
            'external_id' => $externalId,
            'title' => $title,
            'event_type' => 'ManualInteractionRequired',
            'status' => 'manual_required',
            'last_event_at' => now(),
        ]);
    }

    private function extractTitle(ArrIntegration $integration, array $payload): ?string
    {
        if ($integration->isRadarr()) {
            return $payload['movie']['title']
                ?? $payload['remoteMovie']['title']
                ?? null;
        }

        return $payload['series']['title'] ?? null;
    }

    private function extractExternalId(ArrIntegration $integration, array $payload): ?string
    {
        if ($integration->isRadarr()) {
            $id = $payload['movie']['tmdbId'] ?? null;

            return $id !== null ? (string) $id : null;
        }

        $id = $payload['series']['tvdbId'] ?? null;

        return $id !== null ? (string) $id : null;
    }
}
