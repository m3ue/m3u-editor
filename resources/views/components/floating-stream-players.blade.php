<!-- Floating Stream Players Container -->
<div 
    x-data="(() => {
        // Create a unique instance ID to avoid conflicts
        const instanceId = 'floating-streams-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        
        // Only create a new global manager if none exists, or if it's from a different instance
        if (!window._globalMultiStreamManager || window._globalMultiStreamManager._instanceId !== instanceId) {
            // Clean up any existing instance
            if (window._globalMultiStreamManager && typeof window._globalMultiStreamManager.cleanupAllStreams === 'function') {
                try {
                    window._globalMultiStreamManager.cleanupAllStreams();
                } catch (e) {
                    console.warn('Error during cleanup:', e);
                }
            }
            
            // Reset global state
            window._floatingStreamListenerAdded = false;
            
            // Create new instance with unique ID
            const manager = multiStreamManager();
            manager._instanceId = instanceId;
            window._globalMultiStreamManager = manager;
        }
        
        return window._globalMultiStreamManager;
    })()"
    x-init="init()"
    x-on:alpine:destroyed="
        if (typeof cleanupAllStreams === 'function') {
            cleanupAllStreams();
        }
    "
    class="fixed inset-0 pointer-events-none z-[9999]"
>
    <!-- Multiple Floating Players -->
    <template x-for="player in players" :key="player.id">
        <div 
            :style="getPlayerStyle(player)"
            :class="{ 'scale-75 opacity-80': player.isMinimized, 'scale-100 opacity-100': !player.isMinimized }"
            class="pointer-events-auto bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden shadow-2xl hover:shadow-slate-500/25 hover:-translate-y-0.5 transition-all duration-200 ease-in-out"
            @mousedown="bringToFront(player.id)"
        >
            <!-- Player Header/Title Bar -->
            <div 
                class="flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 cursor-move select-none hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors"
                @mousedown="startDrag(player.id, $event)"
            >
                <div class="flex items-center space-x-2 flex-1 min-w-0">
                    <img 
                        x-show="player.logo"
                        :src="player.logo" 
                        :alt="player.title"
                        class="w-5 h-5 rounded object-cover flex-shrink-0"
                        onerror="this.style.display='none'"
                    >
                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="player.title"></span>
                </div>
                
                <div class="flex items-center space-x-1 flex-shrink-0">
                    <!-- Open in New Tab Button -->
                    <button
                        @click.stop="openInNewTab(player, '{{ route('player.popout') }}')"
                        class="p-1 text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded transition-colors focus:outline-none"
                        title="Open in new tab"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="w-3 h-3" />
                    </button>

                    <!-- Minimize Button -->
                    <button 
                        @click.stop="toggleMinimize(player.id)"
                        class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition-colors focus:outline-none"
                        title="Minimize"
                    >
                        <x-heroicon-o-minus class="w-3 h-3" />
                    </button>
                    
                    <!-- Close Button -->
                    <button 
                        @click.stop="closeStream(player.id)"
                        class="p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition-colors focus:outline-none"
                        title="Close"
                    >
                        <x-heroicon-o-x-mark class="w-3 h-3" />
                    </button>
                </div>
            </div>

            <!-- Video Player Area -->
            <div 
                x-show="!player.isMinimized"
                class="relative bg-black group"
                :style="getVideoStyle(player)"
            >
                <!-- Video Element -->
                <video 
                    :id="player.id + '-video'"
                    class="w-full h-full"
                    controls
                    autoplay
                    preload="metadata"
                    x-data="{ playerInstance: null }"
                    :data-stream-url="player.url"
                    :data-stream-format="player.format"
                    x-init="
                        if (window.streamPlayer && $el.dataset.streamUrl && $el.dataset.streamUrl !== '') {
                            playerInstance = window.streamPlayer();
                            playerInstance.initPlayer($el.dataset.streamUrl, $el.dataset.streamFormat, $el.id);
                        }
                    "
                    x-on:beforeunload.window="
                        if (playerInstance && typeof playerInstance.cleanup === 'function') {
                            playerInstance.cleanup();
                        }
                    "
                >
                    <p class="text-white p-4">Your browser does not support video playback.</p>
                </video>
                
                <!-- Loading Overlay -->
                <div 
                    :id="player.id + '-video-loading'"
                    class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-50"
                >
                    <div class="flex items-center space-x-2 text-white">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-xs">Loading...</span>
                    </div>
                </div>

                <!-- Error Overlay -->
                <div 
                    :id="player.id + '-video-error'"
                    class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-75 hidden"
                >
                    <div class="text-center text-white p-4">
                        <x-heroicon-o-exclamation-triangle class="w-8 h-8 mx-auto mb-2 text-red-400" />
                        <p class="text-sm">Failed to load stream</p>
                        <button 
                            class="mt-2 px-3 py-1 bg-red-600 hover:bg-red-700 rounded text-xs transition-colors"
                            @click="
                                const videoEl = document.getElementById(player.id + '-video');
                                if (videoEl && videoEl._streamPlayer) {
                                    videoEl._streamPlayer.initPlayer(player.url, player.format, player.id + '-video');
                                }
                            "
                        >
                            Retry
                        </button>
                    </div>
                </div>

                <!-- Stream Details Toggle -->
                <div class="absolute top-2 left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                    <button 
                        type="button"
                        @click="
                            const overlay = document.getElementById(player.id + '-video-details-overlay');
                            if (overlay) {
                                overlay.classList.toggle('hidden');
                            }
                        "
                        class="bg-black bg-opacity-75 hover:bg-opacity-90 text-white text-xs px-2 py-1 rounded transition-colors"
                        title="Toggle Stream Details"
                    >
                        <x-heroicon-o-information-circle class="w-4 h-4" />
                    </button>
                </div>

                <!-- Stream Details Overlay -->
                <div 
                    :id="player.id + '-video-details-overlay'"
                    class="absolute top-2 left-2 bg-black bg-opacity-90 text-white text-xs p-3 rounded max-w-xs hidden z-10"
                >
                    <div class="flex justify-between items-center mb-2">
                        <span class="font-medium">Stream Details</span>
                        <button 
                            type="button"
                            @click="
                                const overlay = document.getElementById(player.id + '-video-details-overlay');
                                if (overlay) {
                                    overlay.classList.add('hidden');
                                }
                            "
                            class="text-gray-300 hover:text-white"
                        >
                            <x-heroicon-o-x-mark class="w-3 h-3" />
                        </button>
                    </div>
                    <div :id="player.id + '-video-details'" class="space-y-1">
                        <div class="text-gray-400">Loading stream details...</div>
                    </div>
                </div>

                <!-- Resize Handle -->
                <div 
                    class="absolute bottom-0 right-0 w-4 h-4 cursor-se-resize opacity-50 hover:opacity-100 transition-opacity group"
                    @mousedown.stop="startResize(player.id, $event)"
                    title="Resize"
                >
                    <!-- Visual resize indicator with lines -->
                    <div class="absolute bottom-1 right-1 space-y-0.5">
                        <div class="flex space-x-0.5">
                            <div class="w-0.5 h-0.5 bg-gray-400 group-hover:bg-indigo-500 transition-colors"></div>
                            <div class="w-0.5 h-0.5 bg-gray-400 group-hover:bg-indigo-500 transition-colors"></div>
                        </div>
                        <div class="flex space-x-0.5">
                            <div class="w-0.5 h-0.5 bg-gray-400 group-hover:bg-indigo-500 transition-colors"></div>
                            <div class="w-0.5 h-0.5 bg-gray-400 group-hover:bg-indigo-500 transition-colors"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
