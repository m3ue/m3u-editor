<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
@php
$record = $getRecord();
$url = route('m3u-proxy.episode.player', ['id' => $record->id]);
$channelTitle = Str::replace("'", "`", $record->title ?? $record->name);
$playerId = "episode_{$record->id}_preview";
@endphp
    <div 
        x-data="{ 
            player: null,
            playerId: '{{ $playerId }}',
            streamUrl: '{{ $url }}',
            streamFormat: '{{ $format }}',
            channelTitle: '{{ $channelTitle }}'
        }"
        x-init="
            console.log('Series preview initializing:', { playerId, streamUrl, streamFormat });
            // Initialize the stream player when component loads
            $nextTick(() => {
                if (window.streamPlayer) {
                    player = window.streamPlayer();
                    player.initPlayer(streamUrl, streamFormat, playerId);
                } else {
                    console.error('streamPlayer not available');
                }
            });
        "
        x-on:close-modal.window="
            console.log('Series preview cleanup on modal close');
            if (player && typeof player.cleanup === 'function') {
                player.cleanup();
            }
        "
        class="relative bg-black rounded-lg overflow-hidden mb-4 group"
        style="aspect-ratio: 16/9;"
    >
        <!-- Video Element -->
        <video 
            id="{{ $playerId }}"
            class="w-full h-full"
            controls
            autoplay
            preload="metadata"
        >
            <p class="text-white p-4">Your browser does not support video playback.</p>
        </video>
        
        <!-- Loading Overlay -->
        <div 
            id="{{ $playerId }}-loading"
            class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-50"
        >
            <div class="flex items-center space-x-2 text-white">
                <svg class="animate-spin h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Loading stream...</span>
            </div>
        </div>

        <!-- Error Overlay -->
        <div 
            id="{{ $playerId }}-error"
            class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-75 hidden"
        >
            <div class="text-center text-white p-4">
                <svg class="w-12 h-12 mx-auto mb-2 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.315 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
                <h4 class="text-lg font-medium mb-1">Playback Error</h4>
                <p class="text-sm text-gray-300" id="{{ $playerId }}-error-message">Unable to load the stream. Please try again.</p>
                <button 
                    type="button"
                    onclick="retryStream('{{ $playerId }}')"
                    class="mt-3 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-md transition-colors"
                >
                    Retry
                </button>
            </div>
        </div>

        {{-- <!-- Stream Info -->
        <div class="absolute bottom-2 left-2 bg-black bg-opacity-75 rounded px-2 py-1">
            <div class="text-xs text-white">
                <span class="font-medium">{{ $record->title ?? $record->name }}</span>
                <span class="ml-2 text-gray-300" id="{{ $playerId }}-status">Connecting...</span>
            </div>
        </div> --}}

        <!-- Stream Details Toggle -->
        <div class="absolute top-2 left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
            <button 
                type="button"
                onclick="toggleStreamDetails('{{ $playerId }}')"
                class="bg-black bg-opacity-75 hover:bg-opacity-90 text-white text-xs px-2 py-1 rounded transition-colors"
                title="Toggle Stream Details"
            >
                <x-heroicon-o-information-circle class="w-5 h-5" />
            </button>
        </div>

        <!-- Stream Details Overlay -->
        <div 
            id="{{ $playerId }}-details-overlay"
            class="absolute top-2 left-2 bg-black bg-opacity-90 text-white text-xs p-3 rounded max-w-xs hidden"
        >
            <div class="flex justify-between items-center mb-2">
                <span class="font-medium">Stream Details</span>
                <button 
                    type="button"
                    onclick="toggleStreamDetails('{{ $playerId }}')"
                    class="text-gray-300 hover:text-white"
                >
                    <x-heroicon-s-x-mark class="w-4 h-4" />
                </button>
            </div>
            <div id="{{ $playerId }}-details" class="space-y-1">
                <div class="text-gray-400">Loading stream details...</div>
            </div>
        </div>
    </div>

    <x-filament::section collapsible="true" :collapsed="true">
        <x-slot name="heading">
            <div class="flex items-center space-x-2">
                <span>Stream Details</span>
            </div>
        </x-slot>

        <div class="space-y-3">
            <!-- Proxy Status -->
            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="flex items-center space-x-2">
                    <x-heroicon-s-globe-alt class="w-4 h-4 text-gray-400" />
                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Source</span>
                </div>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $proxyEnabled ? 'Via M3U Editor Proxy' : 'Direct from source' }}
                </span>
            </div>

            <!-- Format Information -->
            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="flex items-center space-x-2">
                    <x-heroicon-s-film class="w-4 h-4 text-gray-400" />
                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Format</span>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                        {{ $format === 'hls' || $format === 'm3u8' ? 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200' : 
                           ($format === 'ts' || $format === 'mpegts' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 
                           'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200') }}">
                        {{ strtoupper($format) }}
                    </span>
                </div>
            </div>

            <!-- URL Information -->
            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="flex items-center space-x-2 mb-2">
                    <x-heroicon-s-link class="w-4 h-4 text-gray-400" />
                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Stream URL</span>
                </div>
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded p-2 overflow-hidden">
                    <code class="text-xs text-gray-600 dark:text-gray-300 break-all font-mono">{{ $url }}</code>
                </div>
                <div class="flex items-center justify-between mt-2">
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        @php($parsedUrl = parse_url($url))
                        {{ $parsedUrl['scheme'] ?? 'unknown' }}://{{ $parsedUrl['host'] ?? 'unknown' }}
                    </span>
                </div>
            </div>

            @if($proxyEnabled && $playlist)
                <!-- Proxy Settings -->
                <div class="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                    <div class="flex items-center space-x-2 mb-2">
                        <x-heroicon-s-bolt class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                        <span class="text-sm font-medium text-blue-900 dark:text-blue-100">Proxy Configuration</span>
                    </div>
                    <div class="space-y-1 text-xs text-blue-700 dark:text-blue-300">
                        <div>Output Format: <span class="font-medium">{{ strtoupper($format) }}</span></div>
                    </div>
                </div>
            @endif
        </div>
    </x-filament::section>

    <script>
        function toggleStreamDetails(playerId) {
            const overlay = document.getElementById(playerId + '-details-overlay');
            if (overlay) {
                overlay.classList.toggle('hidden');
            }
        }
    </script>
</x-dynamic-component>
