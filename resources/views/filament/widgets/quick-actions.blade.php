<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between">
                <span>Quick Actions</span>
                <div class="flex items-center space-x-2">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ 
                        $system_health['redis_connected'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' 
                    }}">
                        {{ $system_health['redis_connected'] ? 'Online' : 'Offline' }}
                    </span>
                </div>
            </div>
        </x-slot>

        <div class="space-y-6">
            <!-- System Overview -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $stats['active_streams'] }}</div>
                    <div class="text-xs text-blue-600 dark:text-blue-400">Active Streams</div>
                    <div class="text-xs text-gray-500 mt-1">of {{ $stats['total_streams'] }} total</div>
                </div>
                
                <div class="text-center p-3 {{ $stats['unhealthy_streams'] > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }} rounded-lg">
                    <div class="text-2xl font-bold {{ $stats['unhealthy_streams'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        {{ $stats['unhealthy_streams'] }}
                    </div>
                    <div class="text-xs {{ $stats['unhealthy_streams'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        Unhealthy Streams
                    </div>
                </div>
                
                <div class="text-center p-3 {{ $stats['idle_streams'] > 0 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-green-50 dark:bg-green-900/20' }} rounded-lg">
                    <div class="text-2xl font-bold {{ $stats['idle_streams'] > 0 ? 'text-yellow-600 dark:text-yellow-400' : 'text-green-600 dark:text-green-400' }}">
                        {{ $stats['idle_streams'] }}
                    </div>
                    <div class="text-xs {{ $stats['idle_streams'] > 0 ? 'text-yellow-600 dark:text-yellow-400' : 'text-green-600 dark:text-green-400' }}">
                        Idle Streams
                    </div>
                </div>
                
                <div class="text-center p-3 {{ $system_health['memory_usage'] > 80 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }} rounded-lg">
                    <div class="text-2xl font-bold {{ $system_health['memory_usage'] > 80 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        {{ $system_health['memory_usage'] }}%
                    </div>
                    <div class="text-xs {{ $system_health['memory_usage'] > 80 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        Memory Usage
                    </div>
                </div>
            </div>

            <!-- Quick Action Buttons -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Cleanup Streams -->
                <button 
                    wire:click="cleanupStreams"
                    class="flex flex-col items-center p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors group"
                >
                    <div class="p-2 bg-blue-100 dark:bg-blue-900/50 rounded-lg mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-900/70 transition-colors">
                        <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-gray-900 dark:text-white">Cleanup Streams</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Remove inactive streams and clients</span>
                </button>

                <!-- Refresh Stats -->
                <button 
                    wire:click="refreshSystemStats"
                    class="flex flex-col items-center p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors group"
                >
                    <div class="p-2 bg-green-100 dark:bg-green-900/50 rounded-lg mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-900/70 transition-colors">
                        <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-gray-900 dark:text-white">Refresh Stats</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Update system statistics and metrics</span>
                </button>
            </div>

            <!-- System Resources Bar -->
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-3">System Resources</h4>
                <div class="space-y-3">
                    <!-- Memory Usage -->
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Memory</span>
                        <div class="flex items-center space-x-2">
                            <div class="w-32 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <div class="h-2 rounded-full {{ $system_health['memory_usage'] > 80 ? 'bg-red-500' : ($system_health['memory_usage'] > 60 ? 'bg-yellow-500' : 'bg-green-500') }}" 
                                     style="width: {{ $system_health['memory_usage'] }}%"></div>
                            </div>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $system_health['memory_usage'] }}%</span>
                        </div>
                    </div>

                    <!-- Disk Usage -->
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Disk</span>
                        <div class="flex items-center space-x-2">
                            <div class="w-32 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <div class="h-2 rounded-full {{ $system_health['disk_usage'] > 90 ? 'bg-red-500' : ($system_health['disk_usage'] > 75 ? 'bg-yellow-500' : 'bg-green-500') }}" 
                                     style="width: {{ $system_health['disk_usage'] }}%"></div>
                            </div>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $system_health['disk_usage'] }}%</span>
                        </div>
                    </div>

                    <!-- Load Average -->
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Load Avg</span>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm font-medium {{ $system_health['load_average'] > 2 ? 'text-red-600 dark:text-red-400' : ($system_health['load_average'] > 1 ? 'text-yellow-600 dark:text-yellow-400' : 'text-green-600 dark:text-green-400') }}">
                                {{ number_format($system_health['load_average'], 2) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
