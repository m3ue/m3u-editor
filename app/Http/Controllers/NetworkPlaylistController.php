<?php

namespace App\Http\Controllers;

use App\Models\Network;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * NetworkPlaylistController - Generate M3U playlist for Networks.
 *
 * This controller generates an M3U playlist containing all enabled networks
 * as live TV channels, with EPG URL pointing to the networks EPG endpoint.
 */
class NetworkPlaylistController extends Controller
{
    /**
     * Generate M3U playlist for all user's networks.
     *
     * Route: /networks/{user}/playlist.m3u
     */
    public function __invoke(Request $request, User $user): StreamedResponse
    {
        $networks = Network::where('user_id', $user->id)
            ->where('enabled', true)
            ->orderBy('channel_number')
            ->orderBy('name')
            ->get();

        if ($networks->isEmpty()) {
            abort(404, 'No enabled networks found');
        }

        $baseUrl = url('/');

        return response()->stream(function () use ($networks, $baseUrl, $user) {
            // M3U header with EPG URL pointing to combined networks EPG
            $epgUrl = route('networks.epg', ['user' => $user->id]);
            echo "#EXTM3U x-tvg-url=\"{$epgUrl}\"\n";

            foreach ($networks as $network) {
                $this->outputNetworkChannel($network, $baseUrl);
            }
        }, 200, [
            'Content-Type' => 'audio/x-mpegurl',
            'Content-Disposition' => 'inline; filename="networks.m3u"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Generate M3U playlist for a single network.
     *
     * Route: /network/{network}/playlist.m3u
     */
    public function single(Network $network): RedirectResponse|StreamedResponse
    {
        if (! $network->enabled) {
            abort(404, 'Network is disabled');
        }

        $proxy = new M3uProxyService;

        // If broadcasting is enabled, redirect clients to the proxy HLS playlist
        if ($network->broadcast_enabled) {
            return redirect()->to($proxy->getProxyBroadcastHlsUrl($network));
        }

        $baseUrl = url('/');

        return response()->stream(function () use ($network, $baseUrl) {
            // M3U header with EPG URL
            $epgUrl = $network->epg_url;
            echo "#EXTM3U x-tvg-url=\"{$epgUrl}\"\n";

            $this->outputNetworkChannel($network, $baseUrl);
        }, 200, [
            'Content-Type' => 'audio/x-mpegurl',
            'Content-Disposition' => 'inline; filename="'.$network->name.'.m3u"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Output a single network as an M3U channel entry.
     */
    protected function outputNetworkChannel(Network $network, string $baseUrl): void
    {
        $name = $network->name;
        $channelNumber = $network->channel_number ?? $network->id;
        $tvgId = "network-{$network->id}";
        $logo = $network->logo ?? "{$baseUrl}/placeholder.png";
        $group = 'Networks';
        $streamUrl = $network->stream_url;

        // Build EXTINF line
        $extInf = '#EXTINF:-1';
        $extInf .= " tvg-chno=\"{$channelNumber}\"";
        $extInf .= " tvg-id=\"{$tvgId}\"";
        $extInf .= " tvg-name=\"{$name}\"";
        $extInf .= " tvg-logo=\"{$logo}\"";
        $extInf .= " group-title=\"{$group}\"";
        $extInf .= ",{$name}";

        echo "{$extInf}\n";
        echo "{$streamUrl}\n";
    }

    /**
     * Generate combined EPG for all user's networks.
     *
     * Route: /networks/{user}/epg.xml
     */
    public function epg(Request $request, User $user): StreamedResponse
    {
        $networks = Network::where('user_id', $user->id)
            ->where('enabled', true)
            ->get();

        if ($networks->isEmpty()) {
            abort(404, 'No enabled networks found');
        }

        $epgService = app(\App\Services\NetworkEpgService::class);

        return response()->stream(function () use ($networks, $epgService) {
            $epgService->streamXmltvForNetworks($networks);
        }, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'inline; filename="networks-epg.xml"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Generate M3U playlist for networks belonging to a media server integration.
     *
     * Route: /media-integration/{integration}/networks/playlist.m3u
     */
    public function forIntegration(Request $request, \App\Models\MediaServerIntegration $integration): StreamedResponse
    {
        $networks = Network::where('media_server_integration_id', $integration->id)
            ->where('enabled', true)
            ->orderBy('channel_number')
            ->orderBy('name')
            ->get();

        if ($networks->isEmpty()) {
            abort(404, 'No enabled networks found for this integration');
        }

        $baseUrl = url('/');
        $integrationName = $integration->name;

        return response()->stream(function () use ($networks, $baseUrl, $integration) {
            // M3U header with EPG URL pointing to integration-specific networks EPG
            $epgUrl = route('media-integration.networks.epg', ['integration' => $integration->id]);
            echo "#EXTM3U x-tvg-url=\"{$epgUrl}\"\n";

            foreach ($networks as $network) {
                $this->outputNetworkChannel($network, $baseUrl);
            }
        }, 200, [
            'Content-Type' => 'audio/x-mpegurl',
            'Content-Disposition' => 'inline; filename="'.$integrationName.'-networks.m3u"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Generate EPG for networks belonging to a media server integration.
     *
     * Route: /media-integration/{integration}/networks/epg.xml
     */
    public function epgForIntegration(Request $request, \App\Models\MediaServerIntegration $integration): StreamedResponse
    {
        $networks = Network::where('media_server_integration_id', $integration->id)
            ->where('enabled', true)
            ->get();

        if ($networks->isEmpty()) {
            abort(404, 'No enabled networks found for this integration');
        }

        $epgService = app(\App\Services\NetworkEpgService::class);
        $integrationName = $integration->name;

        return response()->stream(function () use ($networks, $epgService) {
            $epgService->streamXmltvForNetworks($networks);
        }, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'inline; filename="'.$integrationName.'-networks-epg.xml"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Generate a playlist URL for a user's networks.
     */
    public static function generatePlaylistUrl(User $user): string
    {
        return route('networks.playlist', ['user' => $user->id]);
    }

    /**
     * Generate a playlist URL for networks of a media server integration.
     */
    public static function generateIntegrationPlaylistUrl(\App\Models\MediaServerIntegration $integration): string
    {
        return route('media-integration.networks.playlist', ['integration' => $integration->id]);
    }
}
