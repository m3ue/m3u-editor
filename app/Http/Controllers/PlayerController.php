<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlayerController extends Controller
{
    /**
     * Display the popout player view.
     *
     * @return View
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
        if (! in_array($streamFormat, ['ts', 'mpegts', 'hls', 'm3u8', 'mp4', 'm4v', 'mkv', 'webm', 'mov'], true)) {
            $streamFormat = 'ts';
        }

        $channelLogo = (string) $request->query('logo', '');
        $logoHasAllowedAbsoluteScheme = filter_var($channelLogo, FILTER_VALIDATE_URL)
            && in_array(parse_url($channelLogo, PHP_URL_SCHEME), ['http', 'https'], true);
        $logoHasAllowedRelativePath = str_starts_with($channelLogo, '/');

        if ($channelLogo !== '' && ! $logoHasAllowedAbsoluteScheme && ! $logoHasAllowedRelativePath) {
            $channelLogo = '';
        }

        $castUrl = (string) $request->query('cast_url', '');

        if ($castUrl === '') {
            $contentType = (string) $request->query('content_type', '');
            $streamId = (int) $request->query('stream_id', 0);
            $playlistId = (int) $request->query('playlist_id', 0);

            if ($streamId > 0 && $playlistId > 0) {
                $username = auth()->user()?->name ?? null;
                $playlistUuid = Playlist::query()->whereKey($playlistId)->value('uuid');

                if ($username && $playlistUuid) {
                    $castRoute = match ($contentType) {
                        'vod' => 'cast.stream.movie',
                        'episode' => 'cast.stream.series',
                        default => 'cast.stream.live',
                    };

                    $castUrl = route($castRoute, [
                        'username' => $username,
                        'password' => $playlistUuid,
                        'streamId' => $streamId,
                        'format' => 'm3u8',
                    ]);
                }
            }
        }

        $castHasAllowedAbsoluteScheme = filter_var($castUrl, FILTER_VALIDATE_URL)
            && in_array(parse_url($castUrl, PHP_URL_SCHEME), ['http', 'https'], true);
        $castHasAllowedRelativePath = str_starts_with($castUrl, '/');

        if ($castUrl !== '' && ! $castHasAllowedAbsoluteScheme && ! $castHasAllowedRelativePath) {
            $castUrl = '';
        }

        $castFormat = (string) $request->query('cast_format', '');
        if ($castUrl !== '' && $castFormat === '') {
            $castFormat = str_contains($castUrl, '.m3u8') ? 'm3u8' : $streamFormat;
        }
        if ($castFormat === '') {
            $castFormat = $streamFormat;
        }
        if (! in_array($castFormat, ['ts', 'mpegts', 'hls', 'm3u8'], true)) {
            $castFormat = $streamFormat;
        }

        $contentType = (string) $request->query('content_type', '');
        if (! in_array($contentType, ['live', 'vod', 'episode'], true)) {
            $contentType = '';
        }

        $streamId = (int) $request->query('stream_id', 0);
        $playlistId = (int) $request->query('playlist_id', 0);
        $seriesId = (int) $request->query('series_id', 0);
        $seasonNumber = (int) $request->query('season_number', 0);

        $channelTitle = (string) $request->query('display_title', $request->query('title', 'Channel Player'));

        $castUnavailableReason = (string) $request->query('cast_unavailable_reason', '');

        return view('player.popout', [
            'streamUrl' => $streamUrl,
            'streamFormat' => $streamFormat,
            'castUrl' => $castUrl,
            'castFormat' => $castFormat,
            'castUnavailableReason' => $castUnavailableReason,
            'channelTitle' => $channelTitle,
            'channelLogo' => $channelLogo,
            'contentType' => $contentType,
            'streamId' => $streamId ?: null,
            'playlistId' => $playlistId ?: null,
            'seriesId' => $seriesId ?: null,
            'seasonNumber' => $seasonNumber ?: null,
        ]);
    }
}
