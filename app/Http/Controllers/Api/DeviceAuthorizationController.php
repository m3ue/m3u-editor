<?php

namespace App\Http\Controllers\Api;

use App\Filament\Resources\PushDeviceTokens\PushDeviceTokenResource;
use App\Http\Controllers\Controller;
use App\Models\DeviceAuthorization;
use App\Services\DeviceCodeGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceAuthorizationController extends Controller
{
    private const CODE_TTL_MINUTES = 10;

    /**
     * POST /api/device/code
     *
     * Starts a Trakt-style device pairing handshake: issues a short-lived,
     * single-use device_code/user_code pair. The TV app polls `poll()` with
     * the device_code while a human enters the user_code in the admin panel.
     */
    public function requestCode(Request $request): JsonResponse
    {
        abort_unless(PushDeviceTokenResource::isDevicePairingEnabled(), 404);

        $deviceAuth = DeviceAuthorization::create([
            'device_code' => DeviceCodeGeneratorService::generateDeviceCode(),
            'user_code' => DeviceCodeGeneratorService::generateUserCode(),
            'status' => 'pending',
            'requested_ip' => $request->ip(),
            'interval_seconds' => 5,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        return response()->json([
            'device_code' => $deviceAuth->device_code,
            'user_code' => $deviceAuth->user_code,
            'verification_uri' => route('device-pairing.vanity', ['code' => $deviceAuth->user_code]),
            'interval' => $deviceAuth->interval_seconds,
            'expires_in' => now()->diffInSeconds($deviceAuth->expires_at),
        ]);
    }

    /**
     * POST /api/device/token
     *
     * Polled by the TV app until the pairing is approved (or expires/denied).
     * Responses are deliberately generic (never a 404) so callers can't use
     * this endpoint to enumerate whether a device_code ever existed.
     */
    public function poll(Request $request): JsonResponse
    {
        abort_unless(PushDeviceTokenResource::isDevicePairingEnabled(), 404);

        $data = $request->validate([
            'device_code' => ['required', 'string'],
        ]);

        return DB::transaction(function () use ($data): JsonResponse {
            $deviceAuth = DeviceAuthorization::where('device_code', $data['device_code'])
                ->lockForUpdate()
                ->first();

            if ($deviceAuth === null) {
                return response()->json(['status' => 'pending']);
            }

            if ($deviceAuth->isExpired()) {
                $deviceAuth->delete();

                return response()->json(['status' => 'expired']);
            }

            if ($deviceAuth->status === 'denied') {
                $deviceAuth->delete();

                return response()->json(['status' => 'denied']);
            }

            if ($deviceAuth->status === 'approved' && ! $deviceAuth->isConsumed()) {
                $playlistAuth = $deviceAuth->playlistAuth;

                if ($playlistAuth === null) {
                    $deviceAuth->delete();

                    return response()->json(['status' => 'expired']);
                }

                $deviceAuth->update(['consumed_at' => now()]);
                $username = $playlistAuth->username;
                $password = $playlistAuth->password;
                $deviceAuth->delete();

                return response()->json([
                    'status' => 'approved',
                    'username' => $username,
                    'password' => $password,
                ]);
            }

            // Still pending (or already-consumed, which shouldn't be reachable
            // since the row is deleted right after being consumed above).
            $now = now();
            $pacedTooFast = $deviceAuth->last_polled_at !== null
                && $now->diffInSeconds($deviceAuth->last_polled_at) < $deviceAuth->interval_seconds;

            $deviceAuth->increment('poll_attempts');
            $deviceAuth->last_polled_at = $now;

            if ($pacedTooFast) {
                $deviceAuth->interval_seconds += 5;
                $deviceAuth->save();

                return response()->json(['status' => 'slow_down', 'interval' => $deviceAuth->interval_seconds]);
            }

            $deviceAuth->save();

            return response()->json(['status' => 'pending', 'interval' => $deviceAuth->interval_seconds]);
        });
    }
}
