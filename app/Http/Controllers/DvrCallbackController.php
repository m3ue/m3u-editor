<?php

namespace App\Http\Controllers;

use App\Enums\DvrRecordingStatus;
use App\Jobs\PostProcessDvrRecording;
use App\Models\DvrRecording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Use recording_db_id from metadata for unambiguous lookup if available
        $recordingDbId = $data['recording_db_id'] ?? null;
        if ($recordingDbId) {
            $recording = DvrRecording::find($recordingDbId);
            if ($recording) {
                Log::info('DVR callback: matched by recording_db_id', [
                    'recording_id' => $recording->id,
                    'network_id' => $networkId,
                    'event' => $event,
                ]);
            }
        }

        // Fallback to proxy_network_id or uuid lookup
        if (! $recording) {
            $recording = DvrRecording::where('proxy_network_id', $networkId)
                ->orWhere('uuid', $networkId)
                ->first();
        }

        if (! $recording) {
            Log::warning('DVR callback: recording not found', [
                'network_id' => $networkId,
                'recording_db_id' => $recordingDbId,
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
        // Accept Recording/Scheduled and also PostProcessing: the stop() method transitions
        // to PostProcessing immediately when signalling the proxy, but the callback arrives
        // later (after FFmpeg actually exits). We still need to store hls_dir and dispatch
        // the post-processing job regardless of which side set the status first.
        $validStatuses = [
            DvrRecordingStatus::Recording,
            DvrRecordingStatus::Scheduled,
            DvrRecordingStatus::PostProcessing,
        ];

        if (! in_array($recording->status, $validStatuses)) {
            return response()->json(['status' => 'ignored', 'reason' => 'not in recording state']);
        }

        // Only update actual_end if not already set (e.g., by cancel() or finalizeStop()).
        // Then atomically update status and dispatch job in a transaction.
        $updateData = [
            'status' => DvrRecordingStatus::PostProcessing->value,
        ];

        if (! $recording->actual_end) {
            $updateData['actual_end'] = now();
        }

        // hls_dir from the callback is informational only — the downloader pulls
        // files via HTTP from the proxy, not from a shared filesystem path. We
        // intentionally do NOT write it to temp_path.

        DB::transaction(function () use ($recording, $updateData): void {
            $recording->update($updateData);
            PostProcessDvrRecording::dispatch($recording->id)->onQueue('dvr-post');
        });

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
            Log::warning('DVR callback: no proxy token configured — accepting all callbacks (open deployment)');

            return true;
        }

        $providedToken = $request->header('X-API-Token')
            ?? $request->query('api_token')
            ?? '';

        return hash_equals((string) $configuredToken, $providedToken);
    }
}
