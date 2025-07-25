<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlaylistChannelId;
use App\Facades\ProxyFacade;
use App\Http\Controllers\Controller;
use App\Models\Epg;
use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Services\EpgCacheService;
use App\Services\ProxyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EpgApiController extends Controller
{
    /**
     * Get EPG data for viewing with pagination support
     *
     * @param string $uuid
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getData(string $uuid, Request $request)
    {
        $epg = Epg::where('uuid', $uuid)->firstOrFail();

        // Pagination parameters
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);

        // Date parameters
        $startDate = $request->get('start_date', Carbon::now()->format('Y-m-d'));
        $endDate = $request->get('end_date', Carbon::parse($startDate)->format('Y-m-d'));

        Log::debug('EPG API Request', [
            'uuid' => $uuid,
            'page' => $page,
            'per_page' => $perPage,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        try {
            // Check if cache exists and is valid
            if (!$epg->is_cached) {
                return response()->json([
                    'error' => 'Failed to retrieve EPG cache. Please try generating the EPG cache.',
                    'suggestion' => 'Try using the "Generate Cache" button to regenerate the data.'
                ], 500);
            }

            // Get cached channels with pagination
            $cacheService = new EpgCacheService();
            $channelData = $cacheService->getCachedChannels($epg, $page, $perPage);
            $channels = $channelData['channels'];
            $pagination = $channelData['pagination'];

            // Get cached programmes for the requested date and channels
            $channelIds = array_keys($channels);
            $programmes = $cacheService->getCachedProgrammes($epg, $startDate, $channelIds);

            // Get cache metadata
            $metadata = $cacheService->getCacheMetadata($epg);

            return response()->json([
                'epg' => [
                    'id' => $epg->id,
                    'name' => $epg->name,
                    'uuid' => $epg->uuid,
                ],
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'pagination' => $pagination,
                'channels' => $channels,
                'programmes' => $programmes,
                'cache_info' => [
                    'cached' => true,
                    'cache_created' => $metadata['cache_created'] ?? null,
                    'total_programmes' => $metadata['total_programmes'] ?? 0,
                    'programme_date_range' => $metadata['programme_date_range'] ?? null,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Error retrieving EPG data for {$epg->name}: {$e->getMessage()}");
            return response()->json([
                'error' => 'Failed to retrieve EPG data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get EPG data for a specific playlist with pagination support
     *
     * @param string $uuid Playlist UUID
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDataForPlaylist(string $uuid, Request $request)
    {
        // Find the playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            return response()->json(['error' => 'Playlist not found'], 404);
        }
        $cacheService = new EpgCacheService();

        // Pagination parameters
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);

        // Date parameters
        $startDate = $request->get('start_date', Carbon::now()->format('Y-m-d'));
        $endDate = $request->get('end_date', Carbon::parse($startDate)->format('Y-m-d'));

        // Debug logging
        Log::debug('EPG API Request for Playlist', [
            'playlist_uuid' => $uuid,
            'playlist_name' => $playlist->name,
            'page' => $page,
            'per_page' => $perPage,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        try {
            // Get enabled channels from the playlist
            $playlistChannels = $playlist->channels()
                ->leftJoin('groups', 'channels.group_id', '=', 'groups.id')
                ->where('channels.enabled', true)
                ->with(['epgChannel', 'tags', 'group'])
                ->orderBy('groups.sort_order') // Primary sort
                ->orderBy('channels.sort') // Secondary sort
                ->orderBy('channels.channel')
                ->orderBy('channels.title')
                ->select('channels.*')
                ->get();

            // Check the proxy format
            $proxyEnabled = $playlist->enable_proxy;
            $format = $playlist->proxy_options['output'] ?? 'ts';

            // If auto channel increment is enabled, set the starting channel number
            $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;
            $idChannelBy = $playlist->id_channel_by;

            // Group channels by EPG and collect EPG data
            $epgChannelMap = [];
            $epgIds = [];
            $playlistChannelData = [];
            foreach ($playlistChannels as $channel) {
                $epgData = $channel->epgChannel ?? null;
                $channelNo = $channel->channel;
                if (!$channelNo && $playlist->auto_channel_increment) {
                    $channelNo = ++$channelNumber;
                }
                // Get the TVG ID
                switch ($idChannelBy) {
                    case PlaylistChannelId::ChannelId:
                        $tvgId = $channelNo;
                        break;
                    case PlaylistChannelId::Name:
                        $tvgId = $channel->name_custom ?? $channel->name;
                        break;
                    case PlaylistChannelId::Title:
                        $tvgId = $channel->title_custom ?? $channel->title;
                        break;
                    default:
                        $tvgId = $channel->stream_id_custom ?? $channel->stream_id;
                        break;
                }

                if ($epgData) {
                    $epgId = $epgData->epg_id;
                    $epgIds[] = $epgId;
                    if (!isset($epgChannelMap[$epgId])) {
                        $epgChannelMap[$epgId] = [];
                    }

                    // Map EPG channel ID to playlist channel info
                    // Store array of playlist channels for each EPG channel (one-to-many mapping)
                    if (!isset($epgChannelMap[$epgId][$epgData->channel_id])) {
                        $epgChannelMap[$epgId][$epgData->channel_id] = [];
                    }

                    // Add the playlist channel info to the EPG channel map
                    $epgChannelMap[$epgId][$epgData->channel_id][] = [
                        'playlist_channel_id' => $tvgId,
                        'title' => $channel->title_custom ?? $channel->title,
                        'display_name' => $channel->name_custom ?? $channel->name,
                        'channel_number' => $channel->channel,
                        'group' => $channel->group ?? $channel->group_internal,
                        'logo' => $channel->logo ?? ''
                    ];
                }

                // Store channel data for pagination
                $url = $channel->url_custom ?? $channel->url;
                if ($proxyEnabled) {
                    $url = ProxyFacade::getProxyUrlForChannel(
                        id: $channel->id,
                        format: $format
                    );
                }
                $playlistChannelData[] = [
                    'id' => $tvgId,
                    'url' => $url,
                    'title' => $channel->title_custom ?? $channel->title,
                    'display_name' => $channel->name_custom ?? $channel->name,
                    'channel_number' => $channel->channel,
                    'group' => $channel->group ?? $channel->group_internal,
                    'logo' => $channel->logo ?? '',
                    'has_epg' => $epgData !== null,
                    'epg_channel_id' => $epgData->channel_id ?? null
                ];
            }

            // Apply pagination to playlist channels
            $totalChannels = count($playlistChannelData);
            $skip = ($page - 1) * $perPage;
            $paginatedChannels = array_slice($playlistChannelData, $skip, $perPage);

            // Convert to associative array format for consistency with getData
            $channels = [];
            foreach ($paginatedChannels as $channel) {
                $channels[$channel['id']] = $channel;
            }

            // Get EPG data from cache for the paginated channels
            $programmes = [];
            $epgIds = array_unique($epgIds);

            Log::debug("Processing EPG data for " . count($epgIds) . " unique EPGs");
            foreach ($epgIds as $epgId) {
                try {
                    $epg = Epg::find($epgId);
                    if (!$epg) {
                        Log::warning("EPG with ID {$epgId} not found");
                        continue;
                    }

                    // Check if cache exists and is valid
                    if (!$epg->is_cached) {
                        Log::debug("Cache invalid for EPG {$epg->name}, skipping (no auto-regeneration for playlist requests)");
                        continue;
                    }

                    // Get the EPG channel IDs we need for this EPG (only for paginated channels)
                    $neededEpgChannelIds = [];
                    if (isset($epgChannelMap[$epgId])) {
                        foreach ($epgChannelMap[$epgId] as $epgChannelId => $playlistChannelInfoArray) {
                            // Check if any of the playlist channels for this EPG channel are on current page
                            $hasChannelOnPage = false;
                            foreach ($playlistChannelInfoArray as $playlistChannelInfo) {
                                $playlistChannelId = $playlistChannelInfo['playlist_channel_id'];
                                if (isset($channels[$playlistChannelId])) {
                                    $hasChannelOnPage = true;
                                    break;
                                }
                            }

                            if ($hasChannelOnPage) {
                                $neededEpgChannelIds[] = $epgChannelId;
                            }
                        }
                    }

                    if (empty($neededEpgChannelIds)) {
                        continue;
                    }

                    // Get programmes from cache
                    $epgProgrammes = $cacheService->getCachedProgrammes($epg, $startDate, $neededEpgChannelIds);

                    // Map programmes to playlist channels
                    foreach ($epgProgrammes as $epgChannelId => $channelProgrammes) {
                        if (isset($epgChannelMap[$epgId][$epgChannelId])) {
                            $playlistChannelInfoArray = $epgChannelMap[$epgId][$epgChannelId];

                            // Map programmes to all playlist channels that use this EPG channel
                            foreach ($playlistChannelInfoArray as $playlistChannelInfo) {
                                $playlistChannelId = $playlistChannelInfo['playlist_channel_id'];

                                // Only include programmes for channels in current page
                                if (isset($channels[$playlistChannelId])) {
                                    $programmes[$playlistChannelId] = $channelProgrammes;
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("Error processing EPG {$epgId}: {$e->getMessage()}");
                    // Continue with other EPGs
                }
            }

            // Create pagination info
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_channels' => $totalChannels,
                'returned_channels' => count($paginatedChannels),
                'has_more' => ($skip + $perPage) < $totalChannels,
                'next_page' => ($skip + $perPage) < $totalChannels ? $page + 1 : null,
            ];

            return response()->json([
                'playlist' => [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'uuid' => $playlist->uuid,
                    'type' => get_class($playlist),
                ],
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'pagination' => $pagination,
                'channels' => $channels,
                'programmes' => $programmes,
                'cache_info' => [
                    'cached' => true,
                    'epg_count' => count($epgIds),
                    'channels_with_epg' => count(array_filter($playlistChannelData, fn($ch) => $ch['has_epg'])),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Error retrieving EPG data for playlist {$playlist->name}: {$e->getMessage()}");
            return response()->json([
                'error' => 'Failed to retrieve EPG data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Parse XMLTV datetime format
     *
     * @param string $datetime
     * @return Carbon|null
     */
    private function parseXmltvDateTime($datetime)
    {
        try {
            // Format: YYYYMMDDHHMMSS +ZZZZ or YYYYMMDDHHMMSS
            if (preg_match('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\s*([+-]\d{4})?/', $datetime, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
                $hour = $matches[4];
                $minute = $matches[5];
                $second = $matches[6];
                $timezone = $matches[7] ?? '+0000';

                $dateString = "{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}";

                // Convert timezone offset to proper format
                if (preg_match('/([+-])(\d{2})(\d{2})/', $timezone, $tzMatches)) {
                    $tzString = $tzMatches[1] . $tzMatches[2] . ':' . $tzMatches[3];
                    $dateString .= ' ' . $tzString;
                }

                return Carbon::parse($dateString);
            }
        } catch (\Exception $e) {
            // Return null if parsing fails
        }

        return null;
    }
}
