<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $channelTitle }} - Player</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-black text-white">
    <main class="flex h-screen flex-col">
        <header class="flex items-center justify-between border-b border-white/10 bg-black/80 px-4 py-3">
            <div class="flex min-w-0 items-center gap-3">
                @if($channelLogo)
                    <img src="{{ $channelLogo }}" alt="{{ $channelTitle }}" class="h-8 w-8 rounded object-cover"
                        onerror="this.style.display='none'">
                @endif
                <div class="min-w-0">
                    <h1 class="truncate text-sm font-semibold sm:text-base">{{ $channelTitle }}</h1>
                    <p class="text-xs text-white/70">{{ strtoupper($streamFormat) }} Stream</p>
                </div>
            </div>
            <button type="button" id="popin-btn" onclick="popInToMainWindow()"
                class="flex items-center gap-1.5 rounded bg-white/10 px-3 py-1.5 text-xs font-medium text-white/80 transition-colors hover:bg-white/20 hover:text-white"
                title="Send back to floating player in main window">
                <x-heroicon-o-arrow-uturn-left class="h-4 w-4 shrink-0" />
                <span>Pop In</span>
            </button>
        </header>

        <section class="relative flex-1 overflow-hidden group">
            <video id="popout-player" class="h-full w-full" controls autoplay preload="metadata"
                data-url="{{ $streamUrl }}" data-format="{{ $streamFormat }}" data-content-type="{{ $contentType }}"
                data-stream-id="{{ $streamId }}" data-playlist-id="{{ $playlistId }}" data-series-id="{{ $seriesId }}"
                data-season-number="{{ $seasonNumber }}">
                <p class="p-4">Your browser does not support video playback.</p>
            </video>

            <div id="popout-player-loading"
                class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-50">
                <div class="flex items-center gap-2 text-sm">
                    <svg class="h-5 w-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                        </path>
                    </svg>
                    <span>Loading stream...</span>
                </div>
            </div>

            <div id="popout-player-error"
                class="absolute inset-0 hidden items-center justify-center bg-black bg-opacity-75">
                <div class="p-4 text-center">
                    <h2 class="text-lg font-semibold">Playback Error</h2>
                    <p id="popout-player-error-message" class="mt-2 text-sm text-white/75">Unable to load the stream.
                    </p>
                    <button type="button" onclick="retryStream('popout-player')"
                        class="mt-4 rounded bg-blue-600 px-4 py-2 text-sm font-medium hover:bg-blue-500">
                        Retry
                    </button>
                </div>
            </div>

            <div id="popout-player-details-overlay"
                class="absolute top-2 left-2 hidden max-w-xs rounded bg-black/90 p-3 text-xs text-white z-10">
                <div class="mb-2 flex items-center justify-between">
                    <span class="font-medium">Stream Details</span>
                    <button type="button" onclick="toggleStreamDetails('popout-player')"
                        class="text-white/70 hover:text-white">
                        <x-heroicon-o-x-mark class="w-3 h-3" />
                    </button>
                </div>
                <div id="popout-player-details" class="space-y-1">
                    <div class="text-white/60">Loading stream details...</div>
                </div>
            </div>

            <div class="absolute top-2 left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex gap-1">
                <button type="button" onclick="toggleStreamDetails('popout-player')"
                    class="rounded bg-black/75 hover:bg-black/90 px-2 py-1 text-xs text-white transition-colors"
                    title="Toggle Stream Details">
                    <x-heroicon-o-information-circle class="w-4 h-4" />
                </button>
                <button type="button" id="popout-pip-btn" onclick="togglePopoutPiP()"
                    class="rounded bg-black/75 hover:bg-black/90 px-2 py-1 text-xs text-white transition-colors"
                    title="Picture-in-Picture" style="display: none;">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" />
                        <rect x="12" y="9" width="8" height="6" rx="1" fill="currentColor" />
                    </svg>
                </button>
            </div>

            <!-- Resume Prompt (VOD / Episode) -->
            <div id="popout-player-resume"
                class="absolute bottom-14 left-0 right-0 flex justify-center px-4 hidden z-20">
                <div
                    class="bg-gray-900/95 text-white rounded-lg px-4 py-2 flex items-center gap-3 shadow-xl text-sm max-w-sm">
                    <x-heroicon-o-clock class="w-4 h-4 text-blue-400 flex-shrink-0" />
                    <span id="popout-player-resume-time" class="flex-1">Resume from 0:00</span>
                    <button type="button"
                        onclick="document.getElementById('popout-player')._streamPlayer?.resumeFromSaved()"
                        class="px-3 py-1 bg-blue-600 hover:bg-blue-700 rounded text-xs transition-colors flex-shrink-0">Resume</button>
                    <button type="button" onclick="document.getElementById('popout-player')._streamPlayer?.startOver()"
                        class="text-gray-400 hover:text-white transition-colors flex-shrink-0"
                        title="Start from beginning">
                        <x-heroicon-o-x-mark class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (!window.streamPlayer) {
                return;
            }

            const videoElement = document.getElementById('popout-player');
            if (!videoElement) {
                return;
            }

            const streamUrl = videoElement.dataset.url ?? '';
            const streamFormat = videoElement.dataset.format ?? 'ts';

            const player = window.streamPlayer();
            player.initPlayer(streamUrl, streamFormat, 'popout-player');

            // Show PiP button if supported
            if (document.pictureInPictureEnabled) {
                const pipBtn = document.getElementById('popout-pip-btn');
                if (pipBtn) pipBtn.style.display = '';
            }

            window.togglePopoutPiP = function() {
                if (document.pictureInPictureElement === videoElement) {
                    document.exitPictureInPicture().catch(() => {});
                } else if (document.pictureInPictureEnabled) {
                    videoElement.requestPictureInPicture().catch(() => {});
                }
            };

            // Heartbeat: detect whether a main app tab is open
            // Read the source tab ID from the URL (set by openInNewTab)
            const popinBtn = document.getElementById('popin-btn');
            const popinChannel = new BroadcastChannel('m3u-editor-popin');
            const sourceTabId = new URLSearchParams(window.location.search).get('source_tab') || '';
            let mainTabAlive = false;

            function setPopinAvailable(available) {
                mainTabAlive = available;
                popinBtn.disabled = !available;
                if (available) {
                    popinBtn.classList.remove('opacity-40', 'cursor-not-allowed');
                    popinBtn.classList.add('hover:bg-white/20', 'hover:text-white');
                    popinBtn.title = 'Send back to floating player in main window';
                } else {
                    popinBtn.classList.add('opacity-40', 'cursor-not-allowed');
                    popinBtn.classList.remove('hover:bg-white/20', 'hover:text-white');
                    popinBtn.title = 'No open app tab found';
                }
            }

            function pingMainTab() {
                popinChannel.postMessage({ type: 'popin-ping', targetTab: sourceTabId });
                let gotPong = false;
                const handler = (event) => {
                    if (event.data?.type === 'popin-pong') {
                        gotPong = true;
                        setPopinAvailable(true);
                    }
                };
                popinChannel.addEventListener('message', handler);
                setTimeout(() => {
                    popinChannel.removeEventListener('message', handler);
                    if (!gotPong) setPopinAvailable(false);
                }, 500);
            }

            // Initial ping and periodic re-check
            if (sourceTabId) {
                setPopinAvailable(false);
                pingMainTab();
            } else {
                // No source tab (opened directly, not from a floating player) — hide button
                popinBtn.style.display = 'none';
            }
            const heartbeatInterval = sourceTabId ? setInterval(pingMainTab, 3000) : null;

            // Clean up on page unload
            window.addEventListener('pagehide', () => {
                if (heartbeatInterval) clearInterval(heartbeatInterval);
                popinChannel.close();
            }, { once: true });

            // Pop-in: send stream back to the source tab's floating player
            window.popInToMainWindow = function() {
                if (!mainTabAlive) return;

                const data = {
                    id: videoElement.dataset.streamId || null,
                    type: (videoElement.dataset.contentType === 'episode') ? 'episode' : 'channel',
                    title: @json($channelTitle),
                    display_title: @json($channelTitle),
                    logo: @json($channelLogo ?? ''),
                    url: videoElement.dataset.url || '',
                    format: videoElement.dataset.format || 'ts',
                    content_type: videoElement.dataset.contentType || '',
                    stream_id: videoElement.dataset.streamId || null,
                    playlist_id: videoElement.dataset.playlistId || null,
                    series_id: videoElement.dataset.seriesId || null,
                    season_number: videoElement.dataset.seasonNumber || null,
                };

                if (videoElement.currentTime > 0 && isFinite(videoElement.duration)) {
                    data.resume_time = videoElement.currentTime;
                }

                clearInterval(heartbeatInterval);

                popinChannel.postMessage({ type: 'popin-request', targetTab: sourceTabId, channel: data });

                popinBtn.disabled = true;
                popinBtn.textContent = 'Connecting...';
                let done = false;

                const handler = (event) => {
                    if (event.data?.type === 'popin-ack') {
                        done = true;
                        popinChannel.removeEventListener('message', handler);
                        popinChannel.close();
                        window._isPopinTransfer = true;
                        window.close();
                    }
                };
                popinChannel.addEventListener('message', handler);

                setTimeout(() => {
                    if (!done) {
                        popinChannel.removeEventListener('message', handler);
                        setPopinAvailable(false);
                    }
                }, 1500);
            };

            window.addEventListener('beforeunload', () => {
                if (typeof player.cleanup === 'function') {
                    player.cleanup();
                }
            });

            window.addEventListener('pagehide', () => {
                // Skip proxy stop if this is a pop-in transfer (floating player takes over)
                if (!window._isPopinTransfer) {
                    const contentType = videoElement.dataset.contentType || '';
                    const streamId = videoElement.dataset.streamId || '';
                    const type = contentType === 'episode' ? 'episode' : 'channel';
                    if (window.notifyProxyStreamStop) {
                        window.notifyProxyStreamStop(streamId, type);
                    }
                }
                if (typeof player.cleanup === 'function') {
                    player.cleanup();
                }
            });

            document.addEventListener('visibilitychange', () => {
                const isLive = videoElement.dataset.contentType === 'live';
                if (isLive) {
                    return;
                }
                if (document.visibilityState === 'hidden') {
                    videoElement.pause();
                } else if (document.visibilityState === 'visible') {
                    videoElement.play().catch(() => { });
                }
            });
        });
    </script>
</body>

</html>