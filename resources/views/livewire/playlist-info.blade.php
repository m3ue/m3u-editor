<div>
    <!-- Toggle Header -->
    <div class="flex items-center justify-start mb-4">
        <x-filament::button
            :icon="($isVisible ? 'heroicon-s-eye-slash' : 'heroicon-s-eye')"
            icon-position="before"
            color="gray"
            size="xs"
            wire:click="toggleVisibility"
        >
            @if($isVisible)
                Hide
            @else
                Show
            @endif
            Stats
        </x-filament::button>
    </div>
    @if($isVisible)
        <div wire:poll.5s.visible>
            @php($stats = $this->getStats())
            @if(!empty($stats))
                <div class="">
                    @if(isset($stats['is_network_playlist']) && $stats['is_network_playlist'])
                        <!-- Network Playlist Info -->
                        <div class="pb-4">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center">
                                <div class="p-1 mr-1 bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 rounded-lg">
                                    <x-heroicon-s-tv class="text-purple-500 h-4 w-4" />
                                </div>
                                Network Playlist
                            </h3>

                            @if(!($stats['broadcast_service_enabled'] ?? false))
                                <!-- Broadcast Service Warning -->
                                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-4 mb-4">
                                    <div class="flex">
                                        <div class="flex-shrink-0">
                                            <x-heroicon-s-exclamation-circle class="h-5 w-5 text-red-400" />
                                        </div>
                                        <div class="ml-3">
                                            <h3 class="text-sm font-medium text-red-800 dark:text-red-200">
                                                Broadcast Service Not Enabled
                                            </h3>
                                            <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                                                <p>Add <code class="bg-red-100 dark:bg-red-800 px-1 rounded">NETWORK_BROADCAST_ENABLED=true</code> to your <code class="bg-red-100 dark:bg-red-800 px-1 rounded">.env</code> file and restart the container for network streams to work.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            @if(count($stats['networks']) > 0)
                                <div class="space-y-2">
                                    @foreach($stats['networks'] as $network)
                                        <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 rounded-md p-3">
                                            <div class="flex items-center justify-between">
                                                <div class="flex items-center gap-3">
                                                    @if($network['channel_number'])
                                                        <span class="text-xs font-mono bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">
                                                            Ch {{ $network['channel_number'] }}
                                                        </span>
                                                    @endif
                                                    <span class="font-medium text-gray-900 dark:text-gray-100">
                                                        {{ $network['name'] }}
                                                    </span>
                                                    @if($network['media_server'])
                                                        <span class="text-xs text-gray-500 dark:text-gray-400">
                                                            via {{ $network['media_server'] }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    @if($network['broadcast_enabled'])
                                                        @if($network['is_broadcasting'])
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                                🟢 Broadcasting
                                                            </span>
                                                        @else
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                                                ⚪ Not Broadcasting
                                                            </span>
                                                        @endif
                                                    @else
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                                            Broadcast Disabled
                                                        </span>
                                                    @endif
                                                    <a href="/networks/{{ $network['id'] }}/edit" class="text-primary-600 hover:text-primary-500 text-sm">
                                                        Edit
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-md p-4">
                                    <div class="flex">
                                        <div class="flex-shrink-0">
                                            <x-heroicon-s-exclamation-triangle class="h-5 w-5 text-yellow-400" />
                                        </div>
                                        <div class="ml-3">
                                            <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                                                No Networks Assigned
                                            </h3>
                                            <div class="mt-2 text-sm text-yellow-700 dark:text-yellow-300">
                                                <p>Go to <strong>Integrations → Networks</strong> and assign networks to this playlist using the "Output Playlist" field.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @elseif(isset($stats['proxy_enabled']) && $stats['proxy_enabled'])
                        <!-- Proxy Streams Section -->
                        <div class="pb-4">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center">
                                <div class="p-1 mr-1 bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-lg">
                                    <x-heroicon-s-signal class="text-blue-500 h-4 w-4" />
                                </div>
                                Proxy Usage
                            </h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Stream Count -->
                                <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-md p-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Active Connections</span>
                                        <div class="text-right">
                                            <div class="text-lg font-bold text-gray-900 dark:text-gray-100">
                                                {{ $stats['active_connections'] ?? '0' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Max Streams Status -->
                                <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-md p-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Max Reached</span>
                                        <div class="text-right">
                                            @if($stats['max_streams_reached'])
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                                    </svg>
                                                    Yes
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                    </svg>
                                                    No
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if(isset($stats['xtream_info']))
                        <!-- Xtream Info Section -->
                        <div class="pb-4">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center">
                                <div class="p-1 mr-1 bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-lg">
                                    <x-heroicon-s-bolt class="text-green-500 h-4 w-4" />
                                </div>
                                Xtream Provider Details
                            </h3>

                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                                <!-- Active Connections -->
                                <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-md p-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Active Connections</span>
                                        <div class="text-lg font-bold text-gray-900 dark:text-gray-100">
                                            {{ $stats['xtream_info']['active_connections'] }}
                                        </div>
                                    </div>
                                </div>

                                <!-- Expiration Info -->
                                <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-md p-3">
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Expires</span>
                                            <div class="text-lg leading-4 font-bold text-gray-900 dark:text-gray-100">
                                                {{ $stats['xtream_info']['expires'] }}
                                            </div>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 text-right">
                                            {{ $stats['xtream_info']['expires_description'] }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Max Streams Status -->
                                <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-md p-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Max Reached</span>
                                        <div class="text-right">
                                            @if($stats['xtream_info']['max_streams_reached'])
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                                    </svg>
                                                    Yes
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                    </svg>
                                                    No
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Channel & Series Stats Section -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center">
                            <div class="p-1 mr-1 bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-lg">
                                <x-heroicon-s-play class="text-green-500 h-4 w-4" />
                            </div>
                            Channel & Series
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <!-- Channels -->
                            <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-md p-3">
                                <div class="flex flex-col items-center">
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Live</span>
                                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                        {{ $stats['channel_count'] ?? 0 }}
                                    </div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Enabled: {{ $stats['enabled_channel_count'] ?? 0 }}</span>
                                </div>
                            </div>
                            <!-- VOD -->
                            <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-md p-3">
                                <div class="flex flex-col items-center">
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">VOD</span>
                                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                        {{ $stats['vod_count'] ?? 0 }}
                                    </div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Enabled: {{ $stats['enabled_vod_count'] ?? 0 }}</span>
                                </div>
                            </div>
                            <!-- Series -->
                            <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-md p-3">
                                <div class="flex flex-col items-center">
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Series</span>
                                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                        {{ $stats['series_count'] ?? 0 }}
                                    </div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Enabled: {{ $stats['enabled_series_count'] ?? 0 }}</span>
                                </div>
                            </div>
                            <!-- Groups -->
                            <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-md p-3">
                                <div class="flex flex-col items-center">
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Groups</span>
                                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                        {{ $stats['group_count'] ?? 0 }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
