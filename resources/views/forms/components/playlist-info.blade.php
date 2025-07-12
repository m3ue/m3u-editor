<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div wire:poll.5s.visible>
        @php($stats = $getStats())
        @if(!empty($stats))
            <div class="">
                @if(isset($stats['proxy_enabled']) && $stats['proxy_enabled'])
                    <!-- Proxy Streams Section -->
                    <div class="pb-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center">
                            <div class="p-1 mr-1 bg-gray-100 dark:bg-gray-800 rounded-lg">
                                <x-heroicon-s-signal class="text-blue-500 h-4 w-4" />
                            </div>
                            Proxy Usage
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Stream Count -->
                            <div class="bg-gray-100 dark:bg-gray-800 rounded-md p-3">
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
                            <div class="bg-gray-100 dark:bg-gray-800 rounded-md p-3">
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
                            <div class="p-1 mr-1 bg-gray-100 dark:bg-gray-800 rounded-lg">
                                <x-heroicon-s-bolt class="text-green-500 h-4 w-4" />
                            </div>
                            Xtream Provider Details
                        </h3>
                        
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                            <!-- Active Connections -->
                            <div class="bg-gray-100 dark:bg-gray-800 rounded-md p-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Active Connections</span>
                                    <div class="text-lg font-bold text-gray-900 dark:text-gray-100">
                                        {{ $stats['xtream_info']['active_connections'] }}
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Expiration Info -->
                            <div class="bg-gray-100 dark:bg-gray-800 rounded-md p-3">
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
                            <div class="bg-gray-100 dark:bg-gray-800 rounded-md p-3">
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
                        <div class="p-1 mr-1 bg-gray-100 dark:bg-gray-800 rounded-lg">
                            <x-heroicon-s-play class="text-green-500 h-4 w-4" />
                        </div>
                        Channel & Series
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Channels -->
                        <div class="bg-gray-100 dark:bg-gray-800 rounded-md p-3">
                            <div class="flex flex-col items-center">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Live</span>
                                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                    {{ $stats['channel_count'] ?? 0 }}
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Enabled: {{ $stats['enabled_channel_count'] ?? 0 }}</span>
                            </div>
                        </div>
                        <!-- VOD -->
                        <div class="bg-gray-100 dark:bg-gray-800 rounded-md p-3">
                            <div class="flex flex-col items-center">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">VOD</span>
                                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                    {{ $stats['vod_count'] ?? 0 }}
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Enabled: {{ $stats['enabled_vod_count'] ?? 0 }}</span>
                            </div>
                        </div>
                        <!-- Series -->
                        <div class="bg-gray-100 dark:bg-gray-800 rounded-md p-3">
                            <div class="flex flex-col items-center">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Series</span>
                                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                    {{ $stats['series_count'] ?? 0 }}
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Enabled: {{ $stats['enabled_series_count'] ?? 0 }}</span>
                            </div>
                        </div>
                        <!-- Groups -->
                        <div class="bg-gray-100 dark:bg-gray-800 rounded-md p-3">
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
</x-dynamic-component>