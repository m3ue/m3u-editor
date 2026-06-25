<?php

namespace App\Livewire;

use App\Facades\PlaylistFacade;
use App\Models\ArrIntegration;
use App\Models\MediaRequest;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Services\Arr\ArrService;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Livewire\Component;

class GuestQueueStatus extends Component
{
    /**
     * Captured at mount from the route/referer so polls work without a live route param.
     * The UUID is not secret (it's in the URL), and forging it in the Livewire snapshot
     * still requires a matching server-side session entry to load any data.
     */
    public ?string $uuid = null;

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    public function mount(): void
    {
        $this->uuid ??= $this->currentUuid();
        $this->loadQueue();
    }

    public function loadQueue(): void
    {
        $auth = $this->resolveSessionAuth();
        if (! $auth) {
            $this->items = [];

            return;
        }

        $myRequests = MediaRequest::query()
            ->where('playlist_auth_id', $auth->id)
            ->with('arrIntegration')
            ->orderByDesc('requested_at')
            ->limit(50)
            ->get();

        if ($myRequests->isEmpty()) {
            $this->items = [];

            return;
        }

        // Derive the set of guest-enabled integration IDs from the DB — never from client input.
        $allowedIntegrationIds = $this->resolveAllowedIntegrationIds($auth);

        // Only poll integrations with approved requests that are also guest-enabled.
        $approvedIntegrationIds = $myRequests
            ->where('status', 'approved')
            ->pluck('arr_integration_id')
            ->unique()
            ->intersect($allowedIntegrationIds)
            ->values()
            ->all();

        // Build a title → live-item map from the Arr queue for each relevant integration.
        $liveByTitle = [];

        if (! empty($approvedIntegrationIds)) {
            $integrations = ArrIntegration::query()
                ->whereIn('id', $approvedIntegrationIds)
                ->enabled()
                ->get();

            foreach ($integrations as $integration) {
                try {
                    $params = $integration->isSonarr()
                        ? ['includeSeries' => 'true', 'includeEpisode' => 'true']
                        : ['includeMovie' => 'true'];

                    $response = Http::baseUrl($integration->base_url.'/api/v3')
                        ->timeout(5)
                        ->acceptJson()
                        ->withHeaders(['X-Api-Key' => $integration->api_key])
                        ->get('/queue', $params);

                    if ($response->successful()) {
                        $service = ArrService::make($integration);
                        foreach ($service->parseQueueRecords($response->json()['records'] ?? []) as $item) {
                            $key = mb_strtolower(trim($item['title']));
                            $liveByTitle[$key] = $item;
                        }
                    }
                } catch (\Throwable) {
                    // Integration unreachable — degrade gracefully.
                }
            }
        }

        $this->items = $myRequests->map(function (MediaRequest $request) use ($liveByTitle) {
            $live = $liveByTitle[mb_strtolower(trim($request->title))] ?? null;

            $status = match ($request->status) {
                'pending' => 'pending_approval',
                'rejected' => 'rejected',
                'approved' => $live
                    ? ArrQueueMonitor::resolveStatus($live['status'], $live['trackedDownloadState'] ?? null)
                    : 'approved',
                default => $request->status,
            };

            $episodeLabel = $live
                ? ($live['episode'] ?? null)
                : ($request->request_type === 'episode'
                    ? 'S'.str_pad((string) $request->season_number, 2, '0', STR_PAD_LEFT)
                      .'E'.str_pad((string) $request->episode_number, 2, '0', STR_PAD_LEFT)
                    : null);

            return [
                'id' => $request->id,
                'title' => $request->title,
                'status' => $status,
                'integration_name' => $request->arrIntegration?->name,
                'requested_at' => $request->requested_at?->toIso8601String(),
                'episode' => $episodeLabel,
                'progress' => $live ? $live['progress'] : 0,
                'quality' => $live ? ($live['quality'] ?? null) : null,
                'protocol' => $live ? ($live['protocol'] ?? null) : null,
                'size' => $live ? (int) $live['size'] : 0,
                'timeLeft' => $live ? ($live['timeLeft'] ?? null) : null,
                'formattedSize' => ArrQueueMonitor::formatBytes($live ? (int) $live['size'] : 0),
                'can_dismiss' => $status !== 'pending_approval',
            ];
        })->values()->all();
    }

    /**
     * Re-derive the authenticated PlaylistAuth from the guest session on every request.
     * Uses $this->uuid (locked at mount) so polls work without a live route parameter.
     */
    private function resolveSessionAuth(): ?PlaylistAuth
    {
        if (! $this->uuid) {
            return null;
        }

        $username = session(base64_encode($this->uuid).'_guest_auth_username');
        if (! $username) {
            return null;
        }

        return PlaylistAuth::where('username', $username)->first();
    }

    /**
     * Resolve the guest-enabled integration IDs for the playlist this auth belongs to,
     * sourced entirely from the DB — never from a client-supplied prop.
     *
     * @return array<int>
     */
    private function resolveAllowedIntegrationIds(PlaylistAuth $auth): array
    {
        if (! $this->uuid) {
            return [];
        }

        $uuid = $this->uuid;

        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return [];
        }

        $actualPlaylist = $playlist instanceof PlaylistAlias
            ? Playlist::find($playlist->playlist_id)
            : $playlist;

        if (! $actualPlaylist) {
            return [];
        }

        return ArrIntegration::query()
            ->where('user_id', $actualPlaylist->user_id)
            ->enabled()
            ->guestEnabled()
            ->pluck('id')
            ->all();
    }

    /**
     * Remove a request from the guest's history.
     * Pending requests cannot be dismissed — they must be decided by the admin first.
     * Session auth is re-verified on every call; a guest can only delete their own records.
     */
    public function dismissRequest(int $mediaRequestId): void
    {
        $auth = $this->resolveSessionAuth();
        if (! $auth) {
            return;
        }

        MediaRequest::query()
            ->where('id', $mediaRequestId)
            ->where('playlist_auth_id', $auth->id)
            ->whereIn('status', ['approved', 'rejected'])
            ->delete();

        $this->loadQueue();
    }

    /**
     * Mirrors HasGuestAuth::getCurrentUuid() — works on initial page load AND during
     * Livewire AJAX polls, where request()->route('uuid') is null but the Referer
     * header still contains the original guest-panel URL.
     *
     * URL shape: /playlist/v/{uuid}/... → path segment [3] is the UUID.
     */
    private function currentUuid(): ?string
    {
        $referer = request()->header('referer');
        $refererUuid = $referer
            ? (explode('/', parse_url($referer, PHP_URL_PATH))[3] ?? null)
            : null;

        return request()->route('uuid')
            ?? request()->attributes->get('playlist_uuid')
            ?? $refererUuid;
    }

    public function render(): View
    {
        return view('livewire.guest-queue-status');
    }
}
