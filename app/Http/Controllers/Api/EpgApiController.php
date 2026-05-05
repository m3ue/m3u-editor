<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChannelLogoType;
use App\Enums\PlaylistChannelId;
use App\Facades\PlaylistFacade;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LogoProxyController;
use App\Http\Controllers\PlaylistGenerateController;
use App\Models\Epg;
use App\Models\Playlist;
use App\Services\EpgCacheService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
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
        $offset = max(0, ($page - 1) * $perPage);
        $search = $request->get('search', null);

        // Get parsed date range
        $dateRange = $this->parseDateRange($request);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

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
                        $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(display_name) LIKE ?', ['%'.$search.'%']);
                    });
                })
                ->limit($perPage)
                ->offset($offset)
                ->get();

            // Get the channel IDs from database records to fetch cache data
            $channelIds = $epgChannels->pluck('channel_id')->toArray();

            // Get cached channel data for these specific channels
            $cacheService = new EpgCacheService;

            // Build ordered channels array using database order
            $channels = [];
            $channelIndex = $offset;
            foreach ($epgChannels as $epgChannel) {
                $channelId = $epgChannel->channel_id;
                $channels[$channelId] = [
                    'id' => $channelId,
                    'database_id' => $epgChannel->id, // Add the actual database ID for editing
                    'display_name' => $epgChannel->display_name ?? $epgChannel->name ?? $channelId,
                    'icon' => $epgChannel->icon ?? url('/placeholder.png'),
                    'lang' => $epgChannel->lang ?? 'en',
                    'sort_index' => $channelIndex++,
                ];
            }

            // Get cached programmes for the requested date range and channels
            $programmes = $cacheService->getCachedProgrammesRange(
                $epg,
                $startDate,
                $endDate,
                $channelIds
            );

            // Get cache metadata
            $metadata = $cacheService->getCacheMetadata($epg);

            // Create pagination info using database count for accuracy
            $totalChannels = $epg->channels()->when($search, function ($queryBuilder) use ($search) {
                $search = Str::lower($search);

                return $queryBuilder->where(function ($query) use ($search) {
                    $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(display_name) LIKE ?', ['%'.$search.'%']);
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
                'error' => 'Failed to retrieve EPG data: '.$e->getMessage(),
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

        // Handle network playlists - they have networks with programmes instead of channels with EPG
        if ($playlist instanceof Playlist && $playlist->is_network_playlist) {
            return $this->getDataForNetworkPlaylist($playlist, $request);
        }

        $cacheService = new EpgCacheService;
        $user = $playlist->user;

        // Pagination parameters
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);
        $skip = max(0, ($page - 1) * $perPage);
        $search = $request->get('search', null);
        $group = $request->get('group', null) ?: null;
        $username = $request->get('username', null);
        $password = $request->get('password', null);

        // If not username/password provided, use playlist credentials
        if (! $username || ! $password) {
            $username = $user->name ?? 'admin';
            $password = $playlist->uuid;
        }

        // Get parsed date range
        $dateRange = $this->parseDateRange($request);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

        // Debug logging
        Log::debug('EPG API Request for Playlist', [
            'playlist_uuid' => $uuid,
            'playlist_name' => $playlist->name,
            'page' => $page,
            'per_page' => $perPage,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'group' => $group,
        ]);
        try {
            // Get enabled channels from the playlist
            $playlistChannels = PlaylistGenerateController::getChannelQuery($playlist)
                ->when($search, function ($queryBuilder) use ($search) {
                    $search = Str::lower($search);

                    return $queryBuilder->where(function ($query) use ($search) {
                        $query->whereRaw('LOWER(channels.name) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.name_custom) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.title) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.title_custom) LIKE ?', ['%'.$search.'%']);
                    });
                })
                ->when($group, function ($queryBuilder) use ($group) {
                    $g = $queryBuilder->getQuery()->getGrammar();
                    $coalesce = 'COALESCE('.$g->wrap('channels.group').', '.$g->wrap('channels.group_internal').')';

                    return $queryBuilder->whereRaw("LOWER({$coalesce}) = ?", [Str::lower($group)]);
                })
                ->limit($perPage)
                ->offset($skip)
                ->cursor();

            // Check the proxy format
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
            $channelSortIndex = $skip;
            foreach ($playlistChannels as $channel) {
                $epgId = $channel->epg_id ?? null;
                $channelNo = $channel->channel;
                if (! $channelNo && ($playlist->auto_channel_increment || $idChannelBy === PlaylistChannelId::Number)) {
                    $channelNo = ++$channelNumber;
                }

                // Always use the database primary key as the array key to guarantee uniqueness.
                // Duplicate channel numbers would otherwise overwrite earlier entries.
                $channelKey = $channel->id;
                if ($epgId) {
                    $epgIds[] = $epgId;
                    if (! isset($epgChannelMap[$epgId])) {
                        $epgChannelMap[$epgId] = [];
                    }

                    // Map EPG channel ID to playlist channel info
                    // Store array of playlist channels for each EPG channel (one-to-many mapping)
                    if (! isset($epgChannelMap[$epgId][$channel->epg_channel_key])) {
                        $epgChannelMap[$epgId][$channel->epg_channel_key] = [];
                    }

                    $logo = url('/placeholder.png');
                    if ($channel->logo) {
                        // Logo override takes precedence
                        $logo = $channel->logo;
                    } elseif ($channel->logo_type === ChannelLogoType::Epg && ($channel->epg_icon || $channel->epg_icon_custom)) {
                        $logo = $channel->epg_icon_custom ?? $channel->epg_icon ?? '';
                    } elseif ($channel->logo_type === ChannelLogoType::Channel && ($channel->logo || $channel->logo_internal)) {
                        $logo = $channel->logo ?? $channel->logo_internal ?? '';
                        $logo = filter_var($logo, FILTER_VALIDATE_URL) ? $logo : url('/placeholder.png');
                    }
                    if ($logoProxyEnabled) {
                        $logo = LogoProxyController::generateProxyUrl($logo, internal: true);
                    }

                    // Add the playlist channel info to the EPG channel map
                    $epgChannelMap[$epgId][$channel->epg_channel_key][] = [
                        'playlist_channel_id' => $channelKey,
                        'display_name' => $channel->title_custom ?? $channel->title,
                        'title' => $channel->name_custom ?? $channel->name,
                        'channel_number' => $channelNo,
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
                        $icon = LogoProxyController::generateProxyUrl($icon, internal: true);
                    }

                    // Keep track of which channels need a dummy EPG program
                    $dummyEpgChannels[] = [
                        'playlist_channel_id' => $channelKey,
                        'display_name' => $channel->title_custom ?? $channel->title,
                        'display_title' => $channel->display_title,
                        'title' => $channel->name_custom ?? $channel->name,
                        'icon' => $icon,
                        'channel_number' => $channelNo,
                        'group' => $channel->group ?? $channel->group_internal,
                        'include_category' => $playlist->dummy_epg_category,
                    ];
                }

                // Get the TVG ID
                switch ($idChannelBy) {
                    case PlaylistChannelId::ChannelId:
                        $tvgId = $channel->id;
                        break;
                    case PlaylistChannelId::Number:
                        $tvgId = $channelNo;
                        break;
                    case PlaylistChannelId::Name:
                        $tvgId = $channel->name_custom ?? $channel->name;
                        break;
                    case PlaylistChannelId::Title:
                        $tvgId = $channel->title_custom ?? $channel->title;
                        break;
                    default:
                        $tvgId = $channel->source_id ?? $channel->stream_id_custom ?? $channel->stream_id;
                        break;
                }

                // Get the channel URL with embedded auth.
                // XtreamStreamController routes to the channelPlayer method, which
                // applies the in-app transcoding profile.
                $channelResults = $channel->getFloatingPlayerAttributes(username: $username, password: $password);
                $url = $channelResults['url'] ?? '';
                $channelFormat = $channelResults['format'] ?? '';

                // Get the icon
                $icon = '';
                if ($channel->logo) {
                    // Logo override takes precedence
                    $icon = $channel->logo;
                } elseif ($channel->logo_type === ChannelLogoType::Epg && ($channel->epg_icon || $channel->epg_icon_custom)) {
                    $icon = $channel->epg_icon_custom ?? $channel->epg_icon ?? '';
                } elseif ($channel->logo_type === ChannelLogoType::Channel && ($channel->logo || $channel->logo_internal)) {
                    $icon = $channel->logo ?? $channel->logo_internal ?? '';
                    $icon = filter_var($icon, FILTER_VALIDATE_URL) ? $icon : url('/placeholder.png');
                }
                if (empty($icon)) {
                    $icon = url('/placeholder.png');
                }
                if ($logoProxyEnabled) {
                    $icon = LogoProxyController::generateProxyUrl($icon, internal: true);
                }
                $playlistChannelData[$channelKey] = [
                    'id' => $channelKey,
                    'database_id' => $channel->id, // Add the actual database ID for editing
                    'stream_id' => $channel->id,
                    'content_type' => $channel->is_vod ? 'vod' : 'live',
                    'playlist_id' => $playlist->id,
                    'url' => $url,
                    'format' => $channelFormat,
                    'tvg_id' => $tvgId,
                    'display_name' => $channel->title_custom ?? $channel->title,
                    'display_title' => $channelResults['display_title'] ?? $channel->display_title,
                    'title' => $channelResults['title'] ?? $channel->name_custom ?? $channel->name,
                    'channel_number' => $channelNo,
                    'group' => $channel->group ?? $channel->group_internal,
                    'icon' => $icon,
                    'has_epg' => $epgId !== null,
                    'epg_channel_id' => $channel->epg_channel_id ?? null,
                    'tvg_shift' => (int) ($channel->tvg_shift ?? 0), // EPG time shift in hours
                    'sort_index' => $channelSortIndex++,
                ];
            }

            // Apply pagination to playlist channels — use the same base query as the data
            // fetch so the count reflects identical filters (VOD setting, enabled, custom
            // tag deduplication) and stays consistent with the paginated results.
            $totalChannels = PlaylistGenerateController::getChannelQuery($playlist)
                ->when($search, function ($queryBuilder) use ($search) {
                    $search = Str::lower($search);

                    return $queryBuilder->where(function ($query) use ($search) {
                        $query->whereRaw('LOWER(channels.name) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.name_custom) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.title) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.title_custom) LIKE ?', ['%'.$search.'%']);
                    });
                })
                ->when($group, function ($queryBuilder) use ($group) {
                    $g = $queryBuilder->getQuery()->getGrammar();
                    $coalesce = 'COALESCE('.$g->wrap('channels.group').', '.$g->wrap('channels.group_internal').')';

                    return $queryBuilder->whereRaw("LOWER({$coalesce}) = ?", [Str::lower($group)]);
                })
                ->count();

            $channels = $playlistChannelData;

            // Get EPG data from cache for the paginated channels
            $programmes = [];
            $epgIds = array_unique($epgIds);

            Log::debug('Processing EPG data for '.count($epgIds).' unique EPGs');
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

                    // Get programmes from cache for requested date range
                    $epgProgrammes = $cacheService->getCachedProgrammesRange(
                        $epg,
                        $startDate,
                        $endDate,
                        $neededEpgChannelIds
                    );

                    // Map programmes to playlist channels
                    foreach ($epgProgrammes as $epgChannelId => $channelProgrammes) {
                        if (isset($epgChannelMap[$epgId][$epgChannelId])) {
                            $playlistChannelInfoArray = $epgChannelMap[$epgId][$epgChannelId];

                            // Map programmes to all playlist channels that use this EPG channel
                            foreach ($playlistChannelInfoArray as $playlistChannelInfo) {
                                $playlistChannelId = $playlistChannelInfo['playlist_channel_id'];

                                // Only include programmes for channels in current page
                                if (isset($channels[$playlistChannelId])) {
                                    // Apply tvg_shift offset if set
                                    $tvgShift = $channels[$playlistChannelId]['tvg_shift'] ?? 0;

                                    if ($tvgShift !== 0) {
                                        // Offset all programme times by tvg_shift hours
                                        $shiftedProgrammes = array_map(function ($programme) use ($tvgShift) {
                                            $shiftedProgramme = $programme;

                                            // Shift start time
                                            if (isset($programme['start'])) {
                                                $startTime = Carbon::parse($programme['start']);
                                                $shiftedProgramme['start'] = $startTime->addHours($tvgShift)->toIso8601String();
                                            }

                                            // Shift stop time
                                            if (isset($programme['stop'])) {
                                                $stopTime = Carbon::parse($programme['stop']);
                                                $shiftedProgramme['stop'] = $stopTime->addHours($tvgShift)->toIso8601String();
                                            }

                                            return $shiftedProgramme;
                                        }, $channelProgrammes);

                                        $programmes[$playlistChannelId] = $shiftedProgrammes;
                                    } else {
                                        $programmes[$playlistChannelId] = $channelProgrammes;
                                    }
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
                Log::debug('Generating dummy EPG for '.count($dummyEpgChannels).' channels');

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
                    'channels_with_epg' => count(array_filter($playlistChannelData, fn ($ch) => $ch['has_epg'])),
                ],
            ]);
        } catch (Exception $e) {
            Log::error("Error retrieving EPG data for playlist {$playlist->name}: {$e->getMessage()}");

            return response()->json([
                'error' => 'Failed to retrieve EPG data: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get EPG data for a network playlist.
     * Networks act as channels, and their programmes provide the EPG schedule.
     */
    private function getDataForNetworkPlaylist(Playlist $playlist, Request $request)
    {
        // Pagination parameters
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);
        $skip = max(0, ($page - 1) * $perPage);
        $search = $request->get('search', null);

        // Get parsed date range
        $dateRange = $this->parseDateRange($request);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

        Log::debug('EPG API Request for Network Playlist', [
            'playlist_uuid' => $playlist->uuid,
            'playlist_name' => $playlist->name,
            'page' => $page,
            'per_page' => $perPage,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        try {
            // Get enabled networks that output to this playlist
            $networksQuery = $playlist->networks()
                ->where('enabled', true)
                ->when($search, function ($query) use ($search) {
                    $search = Str::lower($search);

                    return $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%']);
                })
                ->orderBy('channel_number')
                ->orderBy('name');

            $totalChannels = $networksQuery->count();
            $networks = $networksQuery->skip($skip)->take($perPage)->get();

            // Build channel data from networks
            $channels = [];
            $programmes = [];

            foreach ($networks as $network) {
                $channelNo = $network->channel_number ?? $network->id;

                // Get the stream URL - use HLS if broadcasting, otherwise legacy endpoint
                $url = $network->stream_url;

                // Get network logo or placeholder
                $icon = $network->logo ?? url('/placeholder.png');

                // Calculate broadcast offset for EPG playhead alignment
                $broadcastOffset = null;
                if ($network->isBroadcasting() && $network->broadcast_started_at) {
                    // Calculate how many seconds the broadcast has been running
                    $broadcastElapsed = (int) $network->broadcast_started_at->diffInSeconds(now());
                    // The actual media position is initial_offset + time since broadcast started
                    $actualMediaPosition = ($network->broadcast_initial_offset ?? 0) + $broadcastElapsed;

                    $broadcastOffset = [
                        'started_at' => $network->broadcast_started_at->toIso8601String(),
                        'initial_offset' => $network->broadcast_initial_offset ?? 0,
                        'broadcast_elapsed' => $broadcastElapsed,
                        'actual_media_position' => $actualMediaPosition,
                    ];
                }

                // Build channel entry
                $channels[$channelNo] = [
                    'id' => $channelNo,
                    'database_id' => null, // $network->id,
                    'url' => $url,
                    'format' => 'hls', // Network streams are HLS
                    'tvg_id' => 'network_'.$network->id,
                    'display_name' => $network->name,
                    'title' => $network->name,
                    'channel_number' => $network->channel_number ?? $channelNo,
                    'group' => $network->effective_group_name,
                    'icon' => $icon,
                    'has_epg' => true, // Networks always have EPG from programmes
                    'epg_channel_id' => 'network_'.$network->id,
                    'tvg_shift' => 0,
                    'sort_index' => $channelNo,
                    'is_network' => true, // Flag to identify network channels
                    'is_broadcasting' => $network->isBroadcasting(),
                    'broadcast_offset' => $broadcastOffset, // For EPG playhead alignment
                ];

                // Get programmes for this network within the date range
                $startDateTime = Carbon::parse($startDate)->startOfDay();
                $endDateTime = Carbon::parse($endDate)->endOfDay();

                $networkProgrammes = $network->programmes()
                    ->where('end_time', '>=', $startDateTime)
                    ->where('start_time', '<=', $endDateTime)
                    ->orderBy('start_time')
                    ->get();

                // Convert to EPG programme format
                $channelProgrammes = [];
                foreach ($networkProgrammes as $programme) {
                    $content = $programme->contentable;
                    $title = $content?->title ?? $content?->name ?? 'Unknown Program';
                    $desc = $content?->overview ?? $content?->description ?? '';

                    // Get content icon if available
                    $programmeIcon = null;
                    if ($content) {
                        $programmeIcon = $content->poster ?? $content->logo ?? null;
                    }

                    $channelProgrammes[] = [
                        'start' => $programme->start_time->toIso8601String(),
                        'stop' => $programme->end_time->toIso8601String(),
                        'title' => $title,
                        'desc' => $desc,
                        'icon' => $programmeIcon,
                        'category' => 'Network Content',
                    ];
                }

                $programmes[$channelNo] = $channelProgrammes;
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
                    'is_network_playlist' => true,
                ],
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'pagination' => $pagination,
                'channels' => $channels,
                'programmes' => $programmes,
                'cache_info' => [
                    'cached' => false, // Network programmes are fetched live
                    'epg_count' => 0,
                    'channels_with_epg' => count($channels), // All networks have EPG
                ],
            ]);
        } catch (Exception $e) {
            Log::error("Error retrieving EPG data for network playlist {$playlist->name}: {$e->getMessage()}");

            return response()->json([
                'error' => 'Failed to retrieve EPG data: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get distinct group names for a playlist's enabled channels.
     * Used to populate the category tabs on the EPG guide page.
     */
    public function getGroupsForPlaylist(string $uuid, Request $request): JsonResponse
    {
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json(['error' => 'Playlist Not Found'], 404);
        }

        $vod = (bool) $request->get('vod', false);
        $includeVod = $vod && $playlist->include_vod_in_m3u;

        $channelQuery = $playlist->channels();
        $g = $channelQuery->getQuery()->getGrammar();
        $coalesce = 'COALESCE('.$g->wrap('channels.group').', '.$g->wrap('channels.group_internal').')';

        $groups = $channelQuery
            ->leftJoin('groups', 'channels.group_id', '=', 'groups.id')
            ->selectRaw("{$coalesce} as effective_group")
            ->when(! $includeVod, function ($q) {
                $q->where('channels.is_vod', false);
            })
            ->where('channels.enabled', true)
            ->where(fn ($q) => $q->where('groups.enabled', true)->orWhereNull('channels.group_id'))
            ->whereRaw("{$coalesce} IS NOT NULL")
            ->whereRaw("{$coalesce} != ''")
            ->groupByRaw($coalesce)
            ->orderByRaw("LOWER({$coalesce})")
            ->pluck('effective_group')
            ->values()
            ->toArray();

        return response()->json(['groups' => $groups]);
    }

    /**
     * Parse and validate date range from request
     *
     * @return array{start: string, end: string} Array with 'start' and 'end' date strings in Y-m-d format
     */
    private function parseDateRange(Request $request): array
    {
        // Date parameters - parse once and reuse Carbon instances
        $startDateInput = $request->get('start_date', Carbon::now()->format('Y-m-d'));
        $endDateInput = $request->get('end_date', $startDateInput);
        $startDateCarbon = Carbon::parse($startDateInput);
        $endDateCarbon = Carbon::parse($endDateInput);

        // Swap dates if start is after end
        if ($startDateCarbon->gt($endDateCarbon)) {
            [$startDateCarbon, $endDateCarbon] = [$endDateCarbon, $startDateCarbon];
        }

        // If starte and end date are the same, add some buffer before/after for programme overlap
        if ($startDateCarbon->gte($endDateCarbon)) {
            $startDateCarbon->subDay();
            $endDateCarbon->addDay();
        }

        return [
            'start' => $startDateCarbon->format('Y-m-d'),
            'end' => $endDateCarbon->format('Y-m-d'),
        ];
    }
}
