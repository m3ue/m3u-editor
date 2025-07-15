<x-filament-panels::page>
    <div wire:poll.5s.visible class="space-y-4">
        @php($statsData = $this->getStatsData())
        @if (!empty($statsData))
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($statsData as $stat)
                    <x-filament::card class="p-4">
                        <!-- Header Section -->
                        <x-filament::avatar
                            src="{{ $stat['logo'] ?? asset('/placeholder.png') }}"
                            alt="Stream logo"
                            :circular="false"
                            size="w-auto h-10"
                            class="flex-shrink-0"
                        />
                        <div class="flex items-center gap-3 mb-4">
                            <div class="min-w-0 flex-1">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white truncate">
                                    {{ $stat['itemName'] ?? 'N/A' }}
                                </h3>
                                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded">{{ $stat['itemType'] ?? 'N/A' }}</span>
                                    @if (($stat['format'] ?? '') === 'MPTS')
                                        <span class="px-2 py-1 bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 rounded">MPTS</span>
                                    @elseif (($stat['format'] ?? '') === 'HLS')
                                        <span class="px-2 py-1 bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300 rounded">HLS</span>
                                    @endif
                                    @if ($stat['isBadSource'] ?? false)
                                        <span class="px-2 py-1 bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 rounded">Bad Source</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Main Info Grid -->
                        <div class="grid grid-cols-2 gap-3 mb-4 text-sm">
                            <div>
                                <span class="text-gray-500 dark:text-gray-400">Playlist:</span>
                                <div class="font-medium truncate">{{ $stat['playlistName'] ?? 'N/A' }}</div>
                            </div>
                            <div>
                                <span class="text-gray-500 dark:text-gray-400">Streams:</span>
                                <div class="font-medium">{{ $stat['activeStreams'] ?? 'N/A' }} / {{ $stat['maxStreams'] ?? 'N/A' }}</div>
                            </div>
                            <div>
                                <span class="text-gray-500 dark:text-gray-400">Output:</span>
                                <div class="font-medium">{{ $stat['codec'] ?? 'N/A' }}</div>
                            </div>
                            <div>
                                <span class="text-gray-500 dark:text-gray-400">Format:</span>
                                <div class="font-medium">{{ $stat['format'] ?? 'N/A' }}</div>
                            </div>
                            @if (!empty($stat['client_ip']))
                                <div>
                                    <span class="text-gray-500 dark:text-gray-400">Client IP:</span>
                                    <div class="font-medium font-mono">{{ $stat['client_ip'] }}</div>
                                </div>
                            @endif
                            @if (!empty($stat['stream_id']))
                                <div>
                                    <span class="text-gray-500 dark:text-gray-400">Stream ID:</span>
                                    <div class="font-medium font-mono text-xs">{{ $stat['stream_id'] }}</div>
                                </div>
                            @endif
                        </div>

                        @if (($stat['itemType'] ?? null) === 'Channel' && !empty($stat['availableStreamsList']))
                            <div class="my-3">
                                <label for="stream-select-{{ $loop->index }}" class="text-sm font-medium text-gray-700 dark:text-gray-300">Available Streams:</label>
                                <select name="stream_select_{{ $loop->index }}" id="stream-select-{{ $loop->index }}" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md dark:bg-gray-700 dark:text-white">
                                    @foreach ($stat['availableStreamsList'] as $stream)
                                        <option
                                            value="{{ $stream['id'] }}"
                                            @if ($stream['id'] == $stat['currentStreamId'])
                                                selected
                                                style="font-weight: bold;"
                                            @endif
                                        >
                                            {{ $stream['name'] }} (Playlist: {{ $stream['playlist_name'] ?? 'N/A' }}) {{ $stream['is_primary'] ? '(Primary)' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <!-- Source Details -->
                        <div class="space-y-3">
                            <!-- Video Section -->
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Video Source</h4>
                                <div class="flex items-center"> <!-- Flex container for logo and details -->
                                    @if (!empty($stat['resolution_logo']))
                                    <div class="mr-3 flex-shrink-0"> <!-- Logo container (left column) -->
                                        <img src="{{ asset($stat['resolution_logo']) }}" alt="Resolution Logo" class="h-10 mr-2">
                                    </div>
                                    @endif

                                    <div class="flex-grow"> <!-- Details container (right column) -->
                                        <div class="text-xs">
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Resolution:</span>
                                                <span class="ml-1 font-mono">{{ $stat['resolution'] ?? 'N/A' }}</span>
                                            </div>
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Codec:</span>
                                                <span class="ml-1 font-mono">{{ $stat['video_codec_long_name'] ?? 'N/A' }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Video tags remain below the flex container --}}
                                @if (!empty($stat['video_tags']) && is_array($stat['video_tags']))
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach($stat['video_tags'] as $key => $value)
                                        @if(!(strtoupper((string)$key) === 'VARIANT_BITRATE' && (string)$value === '0'))
                                            <span class="inline-block px-1.5 py-0.5 bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 rounded text-xs font-mono">
                                                {{ strtoupper((string)$key) }}: {{ is_array($value) ? json_encode($value) : $value }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                                @endif
                            </div>

                            <!-- Audio Section -->
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Audio Source</h4>
                                <div class="grid grid-cols-2 gap-2 text-xs">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Codec:</span>
                                        <span class="ml-1 font-mono">{{ $stat['audio_codec_name'] ?? 'N/A' }}</span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Channels:</span>
                                        <span class="ml-1 font-mono">{{ $stat['audio_channels'] ?? 'N/A' }}</span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Profile:</span>
                                        <span class="ml-1 font-mono">{{ $stat['audio_profile'] ?? 'N/A' }}</span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Layout:</span>
                                        <span class="ml-1 font-mono">{{ $stat['audio_channel_layout'] ?? 'N/A' }}</span>
                                    </div>
                                </div>
                                @if (!empty($stat['audio_tags']) && is_array($stat['audio_tags']))
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach($stat['audio_tags'] as $key => $value)
                                            @if(!(strtoupper((string)$key) === 'VARIANT_BITRATE' && (string)$value === '0'))
                                                <span class="inline-block px-1.5 py-0.5 bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300 rounded text-xs font-mono">
                                                    {{ strtoupper((string)$key) }}: {{ is_array($value) ? json_encode($value) : $value }}
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <!-- Format Section -->
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Format Details</h4>
                                <div class="grid grid-cols-2 gap-2 text-xs">
                                    @if (($stat['itemType'] ?? '') === 'Episode')
                                        <div>
                                            <span class="text-gray-500 dark:text-gray-400">Duration:</span>
                                            <span class="ml-1 font-mono">{{ $stat['format_duration'] ?? 'N/A' }}</span>
                                        </div>
                                        <div>
                                            <span class="text-gray-500 dark:text-gray-400">Size:</span>
                                            <span class="ml-1 font-mono">{{ $stat['format_size'] ?? 'N/A' }}</span>
                                        </div>
                                        <div>
                                            <span class="text-gray-500 dark:text-gray-400">Bitrate:</span>
                                            <span class="ml-1 font-mono">{{ $stat['format_bit_rate'] ?? 'N/A' }}</span>
                                        </div>
                                    @endif
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Streams:</span>
                                        <span class="ml-1 font-mono">{{ $stat['format_nb_streams'] ?? 'N/A' }}</span>
                                    </div>
                                </div>
                                @if (!empty($stat['format_tags']) && is_array($stat['format_tags']))
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach($stat['format_tags'] as $key => $value)
                                            @if(!(strtoupper((string)$key) === 'VARIANT_BITRATE' && (string)$value === '0'))
                                                <span class="inline-block px-1.5 py-0.5 bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300 rounded text-xs font-mono">
                                                    {{ strtoupper((string)$key) }}: {{ is_array($value) ? json_encode($value) : $value }}
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="mt-4 pt-3 border-t border-gray-200 dark:border-gray-700">
                            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                <span>Started:</span>
                                <span class="relative-timestamp font-mono">
                                    {{ $stat['processStartTime'] ?? 'N/A' }}
                                </span>
                            </div>
                        </div>
                    </x-filament::card>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
