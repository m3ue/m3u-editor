<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChannelLogoType;
use App\Enums\PlaylistChannelId;
use App\Facades\PlaylistFacade;
use App\Facades\ProxyFacade;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LogoProxyController;
use App\Models\Epg;
use App\Models\Playlist;
use App\Services\EpgCacheService;
use App\Services\PlaylistUrlService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EpgApiController extends Controller
{
    /**
     * Get EPG data for viewing with pagination support
     *
     * @return JsonResponse
     */
    public function getData(string $uuid, Request $request)
    {
        $epg = Epg::where('uuid', $uuid)->firstOrFail();

        // Pagination parameters
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);
        $search = $request->get('search', null);

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
            if (! $epg->is_cached) {
                return response()->json([
                    'error' => 'Failed to retrieve EPG cache. Please try generating the EPG cache.',
                    'suggestion' => 'Try using the "Generate Cache" button to regenerate the data.',
                ], 500);
            }

            // Use database EpgChannel records for consistent ordering (similar to playlist view)
            $epgChannels = $epg->channels()
                ->orderBy('name')  // Consistent alphabetical ordering
                ->orderBy('channel_id')  // Secondary sort by channel ID
                ->when($search, function ($queryBuilder) use ($search) {
                    $search = Str::lower($search);

                    return $queryBuilder->where(function ($query) use ($search) {
                        $query->whereRaw('LOWER(name) LIKE ?', ['%' . $search . '%'])
                            ->orWhereRaw('LOWER(display_name) LIKE ?', ['%' . $search . '%']);
                    });
                })
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->get();

            // Get the channel IDs from database records to fetch cache data
            $channelIds = $epgChannels->pluck('channel_id')->toArray();

            // Get cached channel data for these specific channels
            $cacheService = new EpgCacheService;

            // Build ordered channels array using database order
            $channels = [];
            foreach ($epgChannels as $epgChannel) {
                $channelId = $epgChannel->channel_id;
                $channels[$channelId] = [
                    'id' => $channelId,
                    'database_id' => $epgChannel->id, // Add the actual database ID for editing
                    'display_name' => $epgChannel->display_name ?? $epgChannel->name ?? $channelId,
                    'icon' => $epgChannel->icon ?? url('/placeholder.png'),
                    'lang' => $epgChannel->lang ?? 'en',
                ];
            }

            // Get cached programmes for the requested date and channels
            $programmes = $cacheService->getCachedProgrammes($epg, $startDate, $channelIds);

            // Get cache metadata
            $metadata = $cacheService->getCacheMetadata($epg);

            // Create pagination info using database count for accuracy
            $totalChannels = $epg->channels()->when($search, function ($queryBuilder) use ($search) {
                $search = Str::lower($search);

                return $queryBuilder->where(function ($query) use ($search) {
                    $query->whereRaw('LOWER(name) LIKE ?', ['%' . $search . '%'])
                        ->orWhereRaw('LOWER(display_name) LIKE ?', ['%' . $search . '%']);
                });
            })->count();
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_channels' => $totalChannels,
                'returned_channels' => count($channels),
                'has_more' => (($page - 1) * $perPage + $perPage) < $totalChannels,
                'next_page' => (($page - 1) * $perPage + $perPage) < $totalChannels ? $page + 1 : null,
            ];

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
                ],
            ]);
        } catch (Exception $e) {
            Log::error("Error retrieving EPG data for {$epg->name}: {$e->getMessage()}");

            return response()->json([
                'error' => 'Failed to retrieve EPG data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get EPG data for a specific playlist with pagination support
     *
     * @param  string  $uuid  Playlist UUID
     * @return JsonResponse
     */
    public function getDataForPlaylist(string $uuid, Request $request)
    {
        // Find the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        $cacheService = new EpgCacheService;

        // Pagination parameters
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);
        $search = $request->get('search', null);
        $vod = (bool) $request->get('vod', false);

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
                ->when(! $vod, function ($query) {
                    return $query->where('channels.is_vod', false);
                })
                ->with(['epgChannel', 'tags', 'group'])
                ->orderBy('groups.sort_order') // Primary sort
                ->orderBy('channels.sort') // Secondary sort
                ->orderBy('channels.channel')
                ->orderBy('channels.title')
                ->when($search, function ($queryBuilder) use ($search) {
                    $search = Str::lower($search);

                    return $queryBuilder->where(function ($query) use ($search) {
                        $query->whereRaw('LOWER(channels.name) LIKE ?', ['%' . $search . '%'])
                            ->orWhereRaw('LOWER(channels.name_custom) LIKE ?', ['%' . $search . '%'])
                            ->orWhereRaw('LOWER(channels.title) LIKE ?', ['%' . $search . '%'])
                            ->orWhereRaw('LOWER(channels.title_custom) LIKE ?', ['%' . $search . '%']);
                    });
                })
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->select('channels.*')
                ->get();

            // Check the proxy format
            $proxyEnabled = $playlist->enable_proxy;
            $logoProxyEnabled = $playlist->enable_logo_proxy;

            // If auto channel increment is enabled, set the starting channel number
            $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;
            $idChannelBy = $playlist->id_channel_by;
            $dummyEpgEnabled = $playlist->dummy_epg;
            $dummyEpgLength = (int) ($playlist->dummy_epg_length ?? 120); // Default to 120 minutes if not set

            // Group channels by EPG and collect EPG data
            $epgChannelMap = [];
            $epgIds = [];
            $dummyEpgChannels = [];
            $playlistChannelData = [];
            foreach ($playlistChannels as $channel) {
                $epgData = $channel->epgChannel ?? null;
                $channelNo = $channel->channel;
                if (! $channelNo) {
                    $channelNo = ++$channelNumber;
                }
                if ($epgData) {
                    $epgId = $epgData->epg_id;
                    $epgIds[] = $epgId;
                    if (! isset($epgChannelMap[$epgId])) {
                        $epgChannelMap[$epgId] = [];
                    }

                    // Map EPG channel ID to playlist channel info
                    // Store array of playlist channels for each EPG channel (one-to-many mapping)
                    if (! isset($epgChannelMap[$epgId][$epgData->channel_id])) {
                        $epgChannelMap[$epgId][$epgData->channel_id] = [];
                    }

                    $logo = $channel->logo ?? $channel->logo_internal ?? '';
                    if ($logoProxyEnabled) {
                        $logo = LogoProxyController::generateProxyUrl($logo);
                    }

                    // Add the playlist channel info to the EPG channel map
                    $epgChannelMap[$epgId][$epgData->channel_id][] = [
                        'playlist_channel_id' => $channelNo,
                        'display_name' => $channel->title_custom ?? $channel->title,
                        'title' => $channel->name_custom ?? $channel->name,
                        'channel_number' => $channel->channel,
                        'group' => $channel->group ?? $channel->group_internal,
                        'logo' => $logo ?? '',
                    ];
                } elseif ($dummyEpgEnabled) {
                    // Get the icon
                    $icon = $channel->logo ?? $channel->logo_internal ?? '';
                    if (empty($icon)) {
                        $icon = url('/placeholder.png');
                    }
                    $icon = htmlspecialchars($icon);
                    if ($logoProxyEnabled) {
                        $icon = LogoProxyController::generateProxyUrl($icon);
                    }

                    // Keep track of which channels need a dummy EPG program
                    $dummyEpgChannels[] = [
                        'playlist_channel_id' => $channelNo,
                        'display_name' => $channel->title_custom ?? $channel->title,
                        'title' => $channel->name_custom ?? $channel->name,
                        'icon' => $icon,
                        'channel_number' => $channel->channel,
                        'group' => $channel->group ?? $channel->group_internal,
                        'include_category' => $playlist->dummy_epg_category,
                    ];
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

                // Store channel data for pagination
                
                // if ($proxyEnabled) {
                //     $url = route('m3u-proxy.channel.player', ['id' => $channel->id]);
                // } else {
                //     $url = PlaylistUrlService::getChannelUrl($channel, $playlist);
                // }

                // Always proxy the internal proxy so we can attempt to transcode the stream for better compatibility
                $url = route('m3u-proxy.channel.player', ['id' => $channel->id]);

                // Determine the channel format based on URL or container extension
                $originalUrl = $channel->url_custom ?? $channel->url;
                if (Str::endsWith($originalUrl, '.m3u8') || Str::endsWith($originalUrl, '.ts')) {
                    $channelFormat = 'ts';
                } else {
                    $channelFormat = $channel->container_extension ?? 'ts';
                }

                // Get the icon
                $icon = '';
                if ($channel->logo) {
                    // Logo override takes precedence
                    $icon = $channel->logo;
                } elseif ($channel->logo_type === ChannelLogoType::Epg) {
                    $icon = $epgData->icon ?? '';
                } elseif ($channel->logo_type === ChannelLogoType::Channel) {
                    $icon = $channel->logo ?? $channel->logo_internal ?? '';
                }
                if (empty($icon)) {
                    $icon = url('/placeholder.png');
                }
                if ($logoProxyEnabled) {
                    $icon = LogoProxyController::generateProxyUrl($icon);
                }
                $playlistChannelData[$channelNo] = [
                    'id' => $channelNo,
                    'database_id' => $channel->id, // Add the actual database ID for editing
                    'url' => $url,
                    'format' => $channelFormat,
                    'tvg_id' => $tvgId,
                    'display_name' => $channel->title_custom ?? $channel->title,
                    'title' => $channel->name_custom ?? $channel->name,
                    'channel_number' => $channel->channel,
                    'group' => $channel->group ?? $channel->group_internal,
                    'icon' => $icon,
                    'has_epg' => $epgData !== null,
                    'epg_channel_id' => $epgData->channel_id ?? null,
                ];
            }

            // Apply pagination to playlist channels
            $totalChannels = $playlist->channels()->when($search, function ($queryBuilder) use ($search) {
                $search = Str::lower($search);

                return $queryBuilder->where(function ($query) use ($search) {
                    $query->whereRaw('LOWER(channels.name) LIKE ?', ['%' . $search . '%'])
                        ->orWhereRaw('LOWER(channels.name_custom) LIKE ?', ['%' . $search . '%'])
                        ->orWhereRaw('LOWER(channels.title) LIKE ?', ['%' . $search . '%'])
                        ->orWhereRaw('LOWER(channels.title_custom) LIKE ?', ['%' . $search . '%']);
                });
            })->when(! $vod, function ($query) {
                return $query->where('channels.is_vod', false);
            })->where('enabled', true)->count();

            $skip = ($page - 1) * $perPage;
            $channels = $playlistChannelData;

            // Get EPG data from cache for the paginated channels
            $programmes = [];
            $epgIds = array_unique($epgIds);

            Log::debug('Processing EPG data for ' . count($epgIds) . ' unique EPGs');
            foreach ($epgIds as $epgId) {
                try {
                    $epg = Epg::find($epgId);
                    if (! $epg) {
                        Log::warning("EPG with ID {$epgId} not found");

                        continue;
                    }

                    // Check if cache exists and is valid
                    if (! $epg->is_cached) {
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
                } catch (Exception $e) {
                    Log::error("Error processing EPG {$epgId}: {$e->getMessage()}");
                    // Continue with other EPGs
                }
            }

            // Generate dummy EPG programmes if enabled
            if (count($dummyEpgChannels) > 0) {
                Log::debug('Generating dummy EPG for ' . count($dummyEpgChannels) . ' channels');

                foreach ($dummyEpgChannels as $dummyEpgChannel) {
                    $playlistChannelId = $dummyEpgChannel['playlist_channel_id'];

                    // Only generate for channels on current page
                    if (! isset($channels[$playlistChannelId])) {
                        continue;
                    }

                    $title = $dummyEpgChannel['title'];
                    $displayName = $dummyEpgChannel['display_name'];
                    $icon = $dummyEpgChannel['icon'];
                    $group = $dummyEpgChannel['group'];
                    $includeCategory = $dummyEpgChannel['include_category'];

                    // Generate dummy programmes for the requested date range
                    $dummyProgrammes = [];

                    // Start from the beginning of the requested start date
                    $currentTime = Carbon::parse($startDate)->startOfDay();
                    $endDateTime = Carbon::parse($endDate)->endOfDay();

                    // Generate programmes in chunks of $dummyEpgLength minutes
                    while ($currentTime->lt($endDateTime)) {
                        $programmeEnd = (clone $currentTime)->addMinutes($dummyEpgLength);

                        // Format times in ISO 8601 format (matching cache format)
                        $programme = [
                            'start' => $currentTime->toIso8601String(),
                            'stop' => $programmeEnd->toIso8601String(),
                            'title' => $title,
                            'desc' => $displayName,
                            'icon' => $icon,
                        ];

                        // Add category if enabled
                        if ($includeCategory && $group) {
                            $programme['category'] = $group;
                        }

                        $dummyProgrammes[] = $programme;
                        $currentTime = $programmeEnd;
                    }

                    // Add to programmes array
                    $programmes[$playlistChannelId] = $dummyProgrammes;
                }
            }

            // Create pagination info
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_channels' => $totalChannels,
                'returned_channels' => count($channels),
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
                ],
            ]);
        } catch (Exception $e) {
            Log::error("Error retrieving EPG data for playlist {$playlist->name}: {$e->getMessage()}");

            return response()->json([
                'error' => 'Failed to retrieve EPG data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
