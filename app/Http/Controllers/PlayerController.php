<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PlayerController extends Controller
{
    /**
     * Display the popout player view.
     *
     * @return \Illuminate\View\View
     */
    public function popout(Request $request)
    {
        $streamUrl = (string) $request->query('url', '');

        $hasAllowedAbsoluteScheme = filter_var($streamUrl, FILTER_VALIDATE_URL)
            && in_array(parse_url($streamUrl, PHP_URL_SCHEME), ['http', 'https'], true);
        $hasAllowedRelativePath = str_starts_with($streamUrl, '/');

        if ($streamUrl === '' || (! $hasAllowedAbsoluteScheme && ! $hasAllowedRelativePath)) {
            abort(404);
        }

        $streamFormat = (string) $request->query('format', 'ts');
        if (! in_array($streamFormat, ['ts', 'mpegts', 'hls', 'm3u8'], true)) {
            $streamFormat = 'ts';
        }

        $channelLogo = (string) $request->query('logo', '');
        $logoHasAllowedAbsoluteScheme = filter_var($channelLogo, FILTER_VALIDATE_URL)
            && in_array(parse_url($channelLogo, PHP_URL_SCHEME), ['http', 'https'], true);
        $logoHasAllowedRelativePath = str_starts_with($channelLogo, '/');

        if ($channelLogo !== '' && ! $logoHasAllowedAbsoluteScheme && ! $logoHasAllowedRelativePath) {
            $channelLogo = '';
        }

        $contentType = (string) $request->query('content_type', '');
        if (! in_array($contentType, ['live', 'vod', 'episode'], true)) {
            $contentType = '';
        }

        $streamId = (int) $request->query('stream_id', 0);
        $playlistId = (int) $request->query('playlist_id', 0);
        $seriesId = (int) $request->query('series_id', 0);
        $seasonNumber = (int) $request->query('season_number', 0);

        return view('player.popout', [
            'streamUrl' => $streamUrl,
            'streamFormat' => $streamFormat,
            'channelTitle' => (string) $request->query('title', 'Channel Player'),
            'channelLogo' => $channelLogo,
            'contentType' => $contentType,
            'streamId' => $streamId ?: null,
            'playlistId' => $playlistId ?: null,
            'seriesId' => $seriesId ?: null,
            'seasonNumber' => $seasonNumber ?: null,
        ]);
    }
}
