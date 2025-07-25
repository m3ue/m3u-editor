<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @php($record = $getRecord())
    @php($playlist = App\Models\Playlist::find($record->playlist_id) ?? null)
    @php($format = $playlist->proxy_options['output'] ?? 'ts')
    @php($url = $record->url_custom ?? $record->url)
    @php($proxyUrl = App\Facades\ProxyFacade::getProxyUrlForChannel(id: $record->id, format: $format, preview: true))
    @php($playerId = "channel_{$record->id}_preview")

    <div 
        x-data="{ 
            player: null,
            playerId: '{{ $playerId }}',
            streamUrl: '{{ $proxyUrl }}',
            streamFormat: '{{ $format }}',
            channelTitle: '{{ $record->name_custom ?? $record->name }}'
        }"
        x-init="
            console.log('Video preview initializing:', { playerId, streamUrl, streamFormat });
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
            console.log('Video preview cleanup on modal close');
            if (player && typeof player.cleanup === 'function') {
                player.cleanup();
            }
        "
        class="relative bg-black rounded-lg overflow-hidden"
        style="aspect-ratio: 16/9;"
    >
        <!-- Video Element -->
        <video 
            id="{{ $playerId }}"
            class="w-full h-full"
            controls
            autoplay
            muted
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
                    onclick="retryStream('{{ $playerId }}')"
                    class="mt-3 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-md transition-colors"
                >
                    Retry
                </button>
            </div>
        </div>

        <!-- Stream Info -->
        <div class="absolute bottom-2 left-2 bg-black bg-opacity-75 rounded px-2 py-1">
            <div class="text-xs text-white">
                <span class="font-medium">{{ $record->name_custom ?? $record->name }}</span>
                <span class="ml-2 text-gray-300" id="{{ $playerId }}-status">Connecting...</span>
            </div>
        </div>
    </div>
</x-dynamic-component>
