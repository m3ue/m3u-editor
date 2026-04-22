<?php

namespace App\Http\Controllers;

use App\Enums\DvrRecordingStatus;
use App\Jobs\PostProcessDvrRecording;
use App\Models\DvrRecording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * DvrCallbackController — Receives webhook callbacks from the m3u-proxy BroadcastManager.
 *
 * The proxy calls this endpoint when a DVR broadcast ends (normally or due to failure).
 * Authentication is via the proxy API token (X-API-Token header or api_token query param),
 * matching the same mechanism the editor uses when calling the proxy.
 *
 * Supported events:
 *   programme_ended   — FFmpeg exited cleanly (duration limit reached)
 *   recording_stopped — Manual stop via the proxy API
 *   broadcast_failed  — FFmpeg encountered a fatal error
 */
class DvrCallbackController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            Log::warning('DVR callback: unauthorized request', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $networkId = $request->input('network_id');
        $event = $request->input('event');
        $hlsDir = $request->input('hls_dir');
        $data = $request->input('data', []);

        if (! $networkId || ! $event) {
            return response()->json(['error' => 'Missing network_id or event'], 422);
        }

        $recording = DvrRecording::where('proxy_network_id', $networkId)
            ->orWhere('uuid', $networkId)
            ->first();

        if (! $recording) {
            Log::warning('DVR callback: recording not found', [
                'network_id' => $networkId,
                'event' => $event,
            ]);

            // Acknowledge so the proxy does not retry indefinitely
            return response()->json(['status' => 'ignored', 'reason' => 'recording not found']);
        }

        Log::info('DVR callback received', [
            'recording_id' => $recording->id,
            'event' => $event,
            'hls_dir' => $hlsDir,
        ]);

        return match ($event) {
            'programme_ended', 'recording_stopped' => $this->handleRecordingStopped($recording, $hlsDir, $data),
            'broadcast_failed' => $this->handleBroadcastFailed($recording, $data),
            default => response()->json(['status' => 'ignored', 'reason' => "unhandled event: {$event}"]),
        };
    }

    private function handleRecordingStopped(DvrRecording $recording, ?string $hlsDir, array $data): JsonResponse
    {
        // Skip if already past this point (e.g. duplicate callback)
        if (! in_array($recording->status, [DvrRecordingStatus::Recording, DvrRecordingStatus::Scheduled])) {
            return response()->json(['status' => 'ignored', 'reason' => 'not in recording state']);
        }

        $recording->update([
            'status' => DvrRecordingStatus::PostProcessing->value,
            'actual_end' => now(),
            'proxy_network_id' => null,
            'temp_path' => $hlsDir ?? $recording->temp_path,
        ]);

        PostProcessDvrRecording::dispatch($recording->id)->onQueue('dvr-post');

        Log::info('DVR callback: dispatched post-processing', [
            'recording_id' => $recording->id,
            'hls_dir' => $hlsDir,
        ]);

        return response()->json(['status' => 'ok']);
    }

    private function handleBroadcastFailed(DvrRecording $recording, array $data): JsonResponse
    {
        if (in_array($recording->status, [DvrRecordingStatus::Completed, DvrRecordingStatus::Cancelled])) {
            return response()->json(['status' => 'ignored', 'reason' => 'already in terminal state']);
        }

        $errorMessage = $data['error'] ?? 'Proxy broadcast failed';

        $recording->update([
            'status' => DvrRecordingStatus::Failed->value,
            'actual_end' => now(),
            'proxy_network_id' => null,
            'error_message' => $errorMessage,
        ]);

        Log::error('DVR callback: broadcast failed', [
            'recording_id' => $recording->id,
            'error' => $errorMessage,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Validate the request using the same API token the editor uses when calling the proxy.
     */
    private function isAuthorized(Request $request): bool
    {
        $configuredToken = config('proxy.m3u_proxy_token');

        // If no token is configured, accept all callbacks (dev/open deployments)
        if (empty($configuredToken)) {
            return true;
        }

        $providedToken = $request->header('X-API-Token')
            ?? $request->query('api_token');

        return $providedToken === $configuredToken;
    }
}
