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
                        <x-heroicon-o-x-mark class="w-6 h-6" />
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
                            preload="metadata"
                            x-data="streamPlayer()"
                            x-init="initPlayer('{{ $streamUrl }}', '{{ $streamFormat }}', '{{ $playerId }}')"
                            data-url="{{ $streamUrl }}"
                            data-format="{{ $streamFormat }}"
                            data-content-type="{{ $contentType }}"
                            data-stream-id="{{ $streamId }}"
                            data-playlist-id="{{ $playlistId }}"
                            data-series-id="{{ $seriesId }}"
                            data-season-number="{{ $seasonNumber }}"
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
                                <x-heroicon-s-exclamation-circle class="w-12 h-12 mx-auto mb-2 text-red-400" />
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

                        <!-- Resume Prompt (VOD / Episode) -->
                        <div
                            id="{{ $playerId }}-resume"
                            class="absolute bottom-14 left-0 right-0 flex justify-center px-4 hidden z-20"
                        >
                            <div class="bg-gray-900/95 text-white rounded-lg px-4 py-2 flex items-center gap-3 shadow-xl text-sm max-w-sm">
                                <x-heroicon-o-clock class="w-4 h-4 text-blue-400 flex-shrink-0" />
                                <span id="{{ $playerId }}-resume-time" class="flex-1">Resume from 0:00</span>
                                <button
                                    type="button"
                                    onclick="document.getElementById('{{ $playerId }}')._streamPlayer?.resumeFromSaved()"
                                    class="px-3 py-1 bg-blue-600 hover:bg-blue-700 rounded text-xs transition-colors flex-shrink-0"
                                >Resume</button>
                                <button
                                    type="button"
                                    onclick="document.getElementById('{{ $playerId }}')._streamPlayer?.startOver()"
                                    class="text-gray-400 hover:text-white transition-colors flex-shrink-0"
                                    title="Start from beginning"
                                >
                                    <x-heroicon-o-x-mark class="w-4 h-4" />
                                </button>
                            </div>
                        </div>

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
</div>
