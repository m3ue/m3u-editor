<?php

namespace App\Http\Controllers;

use App\Models\Network;
use App\Services\NetworkEpgService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NetworkEpgController extends Controller
{
    public function __construct(
        protected NetworkEpgService $epgService
    ) {}

    /**
     * Generate EPG XML for a single network.
     */
    public function show(Network $network): StreamedResponse
    {
        if (! $network->enabled) {
            abort(404, 'Network is disabled');
        }

        // Stream the response for large schedules
        return response()->stream(function () use ($network) {
            $this->epgService->streamXmltvForNetwork($network);
        }, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'inline; filename="'.$network->name.'-epg.xml"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Generate EPG XML for all user networks.
     */
    public function index(Request $request): Response
    {
        // Get user from API token or session
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthorized');
        }

        $networks = Network::where('user_id', $user->id)
            ->where('enabled', true)
            ->get();

        if ($networks->isEmpty()) {
            abort(404, 'No enabled networks found');
        }

        $xml = $this->epgService->generateXmltvForNetworks($networks);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'inline; filename="networks-epg.xml"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Generate compressed EPG XML for a network.
     */
    public function compressed(Network $network): Response
    {
        if (! $network->enabled) {
            abort(404, 'Network is disabled');
        }

        // Generate XML and compress
        $xml = $this->epgService->generateXmltvForNetwork($network);
        $compressed = gzencode($xml);

        return response($compressed, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'inline; filename="'.$network->name.'-epg.xml.gz"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
