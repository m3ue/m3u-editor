<x-filament-panels::page>
    <div x-data="{ 
        refreshInterval: {{ $refreshInterval }},
        autoRefresh: true,
        intervalId: null
    }" x-init="
        intervalId = setInterval(() => {
            if (autoRefresh) {
                $wire.refreshData();
            }
        }, refreshInterval * 1000);
    " x-on:before-unload.window="clearInterval(intervalId)">
        
        <!-- Global Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <x-filament::card class="p-4">
                <div class="flex items-center">
                    <div class="p-2 bg-blue-100 dark:bg-blue-900 rounded-lg">
                        <x-heroicon-s-signal class="h-6 w-6" />
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Streams</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $globalStats['active_streams'] ?? 0 }}</p>
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card class="p-4">
                <div class="flex items-center">
                    <div class="p-2 bg-green-100 dark:bg-green-900 rounded-lg">
                        <x-heroicon-s-user-group class="h-6 w-6" />
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Clients</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $globalStats['total_clients'] ?? 0 }}</p>
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card class="p-4">
                <div class="flex items-center">
                    <div class="p-2 bg-purple-100 dark:bg-purple-900 rounded-lg">
                        <x-heroicon-s-bolt class="h-6 w-6" />
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Bandwidth</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">
                            @php
                                $totalBandwidth = $globalStats['total_bandwidth_kbps'] ?? 0;
                                echo $totalBandwidth > 1000 ? round($totalBandwidth / 1000, 1) . ' Mbps' : $totalBandwidth . ' kbps';
                            @endphp
                        </p>
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card class="p-4">
                <div class="flex items-center">
                    <div class="p-2 bg-indigo-100 dark:bg-indigo-900 rounded-lg">
                        <x-heroicon-s-chart-bar class="h-6 w-6" />
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Avg Clients/Stream</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $globalStats['avg_clients_per_stream'] ?? '0.00' }}</p>
                    </div>
                </div>
            </x-filament::card>
        </div>

        <!-- Auto-refresh toggle -->
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <label class="flex items-center">
                    <input type="checkbox" x-model="autoRefresh" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 dark:text-indigo-400 shadow-sm focus:border-indigo-300 dark:focus:border-indigo-500 focus:ring focus:ring-indigo-200 dark:focus:ring-indigo-800 focus:ring-opacity-50">
                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400">Auto-refresh every {{ $refreshInterval }}s</span>
                </label>
                <span class="text-xs text-gray-500 dark:text-gray-400">Last updated: <span x-text="new Date().toLocaleTimeString()"></span></span>
            </div>
        </div>

        <!-- Streams List -->
        @if($connectionError)
            <x-filament::card class="p-8">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <div class="p-3 bg-red-100 dark:bg-red-900 rounded-lg">
                            <x-heroicon-s-exclamation-triangle class="h-8 w-8 text-red-600 dark:text-red-300" />
                        </div>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-lg font-semibold text-red-900 dark:text-red-100 mb-2">
                            Unable to Connect to <strong>m3u proxy
                        </h3>
                        <p class="text-sm text-red-800 dark:text-red-200 mb-3">
                            {{ $connectionError }}
                        </p>
                        <div class="text-sm text-red-700 dark:text-red-300">
                            <p class="font-medium mb-2">Please verify:</p>
                            <ul class="list-disc list-inside space-y-1 ml-2">
                                <li>The m3u-proxy server is running</li>
                                <li>The proxy URL is configured correctly: <code class="px-1 py-0.5 bg-red-200 dark:bg-red-800 rounded text-xs">{{ config('proxy.m3u_proxy_url') }}</code></li>
                                <li>There are no firewall or network issues blocking the connection</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </x-filament::card>
        @elseif(empty($streams))
            <x-filament::card class="p-8 text-center">
                <div class="text-gray-500 dark:text-gray-400">
                    <div class="w-full flex items-center justify-center mb-4">
                        <x-heroicon-s-video-camera class="h-12 w-12" />
                    </div>
                    <p class="text-lg font-medium">No active streams</p>
                    <p class="text-sm">Streams will appear here when clients connect</p>
                </div>
            </x-filament::card>
        @else
            <div class="space-y-4">
                @foreach($streams as $stream)
                    <x-filament::card>
                        <div class="p-6" x-data="{ showClients: false, showDetails: false }">
                            <!-- Stream Header -->
                            <div class="md:flex items-center justify-between mb-4">
                                <div class="md:flex items-center space-x-0 md:space-x-4 space-y-2 md:space-y-0">
                                    <div class="flex-shrink-0">
                                        <div class="h-10 w-10 rounded-full flex items-center justify-center {{ 
                                            $stream['status'] === 'active' ? 'bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-300' : 
                                            ($stream['status'] === 'idle' ? 'bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300' : 'bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-300') 
                                        }}">
                                            @if($stream['status'] === 'idle')
                                                <x-heroicon-s-pause class="w-5 h-5" />
                                            @elseif($stream['status'] === 'active')
                                                <x-heroicon-s-play class="w-5 h-5" />
                                            @else
                                                <x-heroicon-s-exclamation-triangle class="w-5 h-5" />
                                            @endif
                                        </div>
                                    </div>
                                    @if($stream['model']['logo'] ?? false)
                                        <div class="flex-1 min-w-0">
                                            <div>
                                                <img src="{{ $stream['model']['logo'] }}" alt="Stream Thumbnail" class="h-10 w-auto rounded-md object-cover">
                                            </div>
                                        </div>
                                    @endif
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                            Stream {{ substr($stream['stream_id'], -8) }}
                                        </h3>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 font-mono">{{ $stream['model']['title'] ?? 'N/A' }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 font-mono">{{ $stream['source_url'] }}</p>
                                    </div>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200">
                                        {{ $stream['format'] }}
                                    </span>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ 
                                        $stream['status'] === 'active' ? 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' : 
                                        ($stream['status'] === 'idle' ? 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200' : 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200') 
                                    }}">
                                        {{ ucfirst($stream['status']) }}
                                    </span>
                                </div>
                            </div>

                            <!-- Stream Stats Grid -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Clients</div>
                                    <div class="text-lg font-semibold text-gray-900 dark:text-white">{{ $stream['client_count'] }}</div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Bandwidth</div>
                                    <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                        {{ $stream['bandwidth_kbps'] > 1000 ? round($stream['bandwidth_kbps'] / 1000, 1) . ' Mbps' : $stream['bandwidth_kbps'] . ' kbps' }}
                                    </div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Data Transferred</div>
                                    <div class="text-lg font-semibold text-gray-900 dark:text-white">{{ $stream['bytes_transferred'] }}</div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Uptime</div>
                                    <div class="text-lg font-semibold text-gray-900 dark:text-white">{{ $stream['uptime'] }}</div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2">
                                    <button @click="showClients = !showClients" 
                                            class="inline-flex items-center px-3 py-1.5 border border-gray-300 dark:border-gray-600 shadow-sm text-xs font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-800 focus:ring-indigo-500 dark:focus:ring-indigo-400">
                                        <span x-text="showClients ? 'Hide Clients' : 'Show Clients ({{ $stream['client_count'] }})'"></span>
                                    </button>
                                    <button @click="showDetails = !showDetails"
                                            class="inline-flex items-center px-3 py-1.5 border border-gray-300 dark:border-gray-600 shadow-sm text-xs font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-800 focus:ring-indigo-500 dark:focus:ring-indigo-400">
                                        <span x-text="showDetails ? 'Hide Details' : 'Show Details'"></span>
                                    </button>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <button wire:click="stopStream('{{ $stream['stream_id'] }}')" 
                                            class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-800 focus:ring-red-500">
                                        Remove Stream
                                    </button>
                                </div>
                            </div>

                            <!-- Clients List -->
                            <div x-show="showClients" x-transition class="mt-4 border-t dark:border-gray-700 pt-4" style="display: none;">
                                @if(empty($stream['clients']))
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No active clients</p>
                                @else
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                            <thead class="bg-gray-50 dark:bg-gray-800">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Client IP</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Connected</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Duration</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                                @foreach($stream['clients'] as $client)
                                                    <tr>
                                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white">{{ $client['ip'] }}</td>
                                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $client['connected_at'] }}</td>
                                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $client['duration'] }}</td>
                                                        <td class="px-3 py-2 whitespace-nowrap">
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $client['is_active'] ? 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' : 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' }}">
                                                                {{ $client['is_active'] ? 'Active' : 'Inactive' }}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>

                            <!-- Stream Details -->
                            <div x-show="showDetails" x-transition class="mt-4 border-t dark:border-gray-700 pt-4" style="display: none;">
                                <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Stream ID:</span>
                                        <div class="font-mono text-xs break-all text-gray-900 dark:text-gray-100">{{ $stream['stream_id'] }}</div>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Started At:</span>
                                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $stream['started_at'] ?? 'N/A' }}</div>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Process Status:</span>
                                        <div class="font-medium">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $stream['process_running'] ? 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' : 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' }}">
                                                {{ $stream['process_running'] ? 'Running' : 'Idle' }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </x-filament::card>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
