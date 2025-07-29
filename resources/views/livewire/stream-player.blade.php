<div>
    <!-- Stream Player Modal -->
    <div 
        x-data="{ show: @entangle('showModal') }"
        x-show="show"
        x-transition.opacity.duration.300ms
        class="fixed inset-0 z-50 overflow-y-auto"
        style="display: none;"
        x-init="
            console.log('StreamPlayer modal initialized');
            window.addEventListener('openStreamPlayer', function(event) {
                console.log('Window event received:', event.detail);
                $wire.openPlayer(event.detail);
            });
        "
        x-watch="show"
        x-effect="
            if (!show) {
                console.log('Modal closing, triggering cleanup...');
                // Find the video element and trigger cleanup
                const video = document.querySelector('#{{ $playerId }}');
                if (video) {
                    const alpineData = Alpine.$data(video);
                    if (alpineData && typeof alpineData.cleanup === 'function') {
                        alpineData.cleanup();
                    }
                }
            }
        "
        @openstreamplayer.window="console.log('openStreamPlayer event received:', $event.detail)"
        @cleanup-player.window="
            console.log('Cleanup player event received:', $event.detail);
            const video = document.getElementById($event.detail.playerId);
            if (video) {
                const alpineData = Alpine.$data(video);
                if (alpineData && typeof alpineData.cleanup === 'function') {
                    alpineData.cleanup();
                }
            }
        "
    >
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <!-- Backdrop -->
            <div 
                class="fixed inset-0 bg-black bg-opacity-75 transition-opacity"
                @click="$wire.closePlayer()"
            ></div>
            
            <!-- Modal Content -->
            <div 
                class="inline-block w-full max-w-4xl my-8 overflow-hidden text-left align-middle transition-all transform bg-white dark:bg-gray-800 shadow-xl rounded-lg"
                x-show="show"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 transform translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 transform translate-y-0 sm:scale-100"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 transform translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 transform translate-y-4 sm:translate-y-0 sm:scale-95"
            >
                <!-- Header -->
                <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center space-x-3">
                        @if($channelLogo)
                            <img 
                                src="{{ $channelLogo }}" 
                                alt="{{ $channelTitle }}"
                                class="w-8 h-8 rounded object-cover"
                                onerror="this.style.display='none'"
                            >
                        @endif
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $channelTitle }}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ strtoupper($streamFormat) }} Stream</p>
                        </div>
                    </div>
                    <button 
                        @click="$wire.closePlayer()"
                        class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Video Player -->
                <div class="p-4">
                    <div class="relative bg-black rounded-lg overflow-hidden group" style="aspect-ratio: 16/9;">
                        <video 
                            id="{{ $playerId }}"
                            class="w-full h-full"
                            controls
                            autoplay
                            muted
                            x-data="streamPlayer()"
                            x-init="initPlayer('{{ $streamUrl }}', '{{ $streamFormat }}', '{{ $playerId }}')"
                            data-url="{{ $streamUrl }}"
                            data-format="{{ $streamFormat }}"
                            @cleanup-player.window="
                                console.log('Video cleanup event:', $event.detail);
                                if ($event.detail.playerId === '{{ $playerId }}') {
                                    console.log('Cleaning up this video player');
                                    cleanup();
                                }
                            "
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

                        <!-- Stream Details Toggle -->
                        <div class="absolute top-2 left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                            <button 
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

                    {{-- <!-- Stream Info -->
                    <div class="mt-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="font-medium text-gray-700 dark:text-gray-300">Format:</span>
                                <span class="text-gray-600 dark:text-gray-400 ml-2">{{ strtoupper($streamFormat) }}</span>
                            </div>
                            <div>
                                <span class="font-medium text-gray-700 dark:text-gray-300">Status:</span>
                                <span id="{{ $playerId }}-status" class="text-gray-600 dark:text-gray-400 ml-2">Connecting...</span>
                            </div>
                        </div>
                    </div> --}}
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleStreamDetails(playerId) {
            const overlay = document.getElementById(playerId + '-details-overlay');
            if (overlay) {
                overlay.classList.toggle('hidden');
            }
        }
    </script>
</div>
