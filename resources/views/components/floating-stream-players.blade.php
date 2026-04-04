<!-- Floating Stream Players Container -->
<div 
    x-data="multiStreamManager()"
    x-init="init()"
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
                        :alt="player.display_title || player.title"
                        class="w-5 h-5 rounded object-cover flex-shrink-0"
                        onerror="this.style.display='none'"
                    >
                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="player.display_title || player.title"></span>
                </div>
                
                <div class="flex items-center space-x-1 flex-shrink-0">
                    <!-- Cast to Chromecast Button -->
                    <button
                        x-show="$store.cast && $store.cast.isReady"
                        x-cloak
                        @click.stop="
                            const castUrl = player.cast_url || '';
                            const castFormat = player.cast_format || player.format;
                            const castContentType = player.content_type || null;

                            if (!castUrl) {
                                console.warn('[CastManager] No cast-safe URL available for player', {
                                    title: player.display_title || player.title,
                                    playerId: player.id,
                                    streamId: player.stream_id,
                                });
                                return;
                            }

                            if ($store.cast.isCasting && $store.cast.currentStreamUrl === castUrl) {
                                $store.cast.stopCast();
                            } else {
                                const videoEl = document.getElementById(player.id + '-video');
                                $store.cast.startCast(
                                    castUrl, castFormat, player.display_title || player.title, player.logo,
                                    () => {
                                        // Stop local playback to free the proxy connection
                                        if (videoEl) {
                                            videoEl.dataset.localPlaybackSuspendedForCast = 'true';
                                        }
                                        if (videoEl && videoEl._streamPlayer) {
                                            videoEl._streamPlayer.cleanup();
                                        }
                                        // Show the casting overlay
                                        if (window.streamPlayer) {
                                            window.streamPlayer().showError(player.id + '-video', 'Casting');
                                        }
                                    },
                                    () => {
                                        // Resume local playback when cast ends
                                        if (videoEl) {
                                            delete videoEl.dataset.localPlaybackSuspendedForCast;
                                        }
                                        if (videoEl && window.streamPlayer) {
                                            const sp = window.streamPlayer();
                                            sp.initPlayer(player.url, player.format, player.id + '-video');
                                        }
                                    },
                                    castContentType
                                );
                            }
                        "
                        :class="{
                            'text-blue-500 dark:text-blue-400': player.cast_url && $store.cast.isCasting && $store.cast.currentStreamUrl === player.cast_url,
                            'text-gray-400 hover:text-blue-600 dark:hover:text-blue-400': !(player.cast_url && $store.cast.isCasting && $store.cast.currentStreamUrl === player.cast_url),
                            'opacity-40 cursor-not-allowed hover:text-gray-400 dark:hover:text-gray-400': !player.cast_url,
                        }"
                        :disabled="!player.cast_url"
                        class="p-1 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded transition-colors focus:outline-none disabled:hover:bg-transparent"
                        :title="!player.cast_url ? (player.cast_unavailable_reason || 'Cast unavailable for this stream') : ($store.cast.isCasting && $store.cast.currentStreamUrl === player.cast_url ? 'Stop casting' : 'Cast to Chromecast')"
                    >
                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                            <path d="M1 18v3h3c0-1.66-1.34-3-3-3zm0-4v2c2.76 0 5 2.24 5 5h2c0-3.87-3.13-7-7-7zm0-4v2c4.97 0 9 4.03 9 9h2c0-6.08-4.93-11-11-11zm20-7H3c-1.1 0-2 .9-2 2v3h2V5h18v14h-7v2h7c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                        </svg>
                    </button>

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
                    :data-content-type="player.content_type || ''"
                    :data-stream-id="player.stream_id || ''"
                    :data-playlist-id="player.playlist_id || ''"
                    :data-series-id="player.series_id || ''"
                    :data-season-number="player.season_number || ''"
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
                    x-on:pagehide.window="
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
                        <div :id="player.id + '-video-error-icon'" class="mx-auto mb-2 flex justify-center">
                            <x-heroicon-o-exclamation-triangle class="w-8 h-8 text-red-400" />
                        </div>
                        <p :id="player.id + '-video-error-title'" class="text-sm font-medium">Playback Error</p>
                        <p :id="player.id + '-video-error-message'" class="mt-1 text-xs text-white/75">Failed to load stream</p>
                        <button 
                            :id="player.id + '-video-error-retry'"
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

                <!-- Resume Prompt (VOD / Episode) -->
                <div
                    :id="player.id + '-video-resume'"
                    class="absolute bottom-10 left-0 right-0 flex justify-center px-3 hidden z-20"
                >
                    <div class="bg-gray-900/95 text-white rounded-lg px-3 py-2 flex items-center gap-3 shadow-xl text-xs max-w-xs">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <span :id="player.id + '-video-resume-time'" class="flex-1 truncate">Resume from 0:00</span>
                        <button
                            class="px-2 py-1 bg-blue-600 hover:bg-blue-700 rounded transition-colors flex-shrink-0"
                            @click.stop="
                                const v = document.getElementById(player.id + '-video');
                                if (v && v._streamPlayer) v._streamPlayer.resumeFromSaved();
                            "
                        >Resume</button>
                        <button
                            class="text-gray-400 hover:text-white transition-colors flex-shrink-0"
                            @click.stop="
                                const v = document.getElementById(player.id + '-video');
                                if (v && v._streamPlayer) v._streamPlayer.startOver();
                            "
                            title="Start from beginning"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
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
