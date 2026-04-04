<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $channelTitle }} - Player</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://www.gstatic.com/cv/js/sender/v1/cast_sender.js?loadCastFramework=1"></script>
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
            <div id="cast-button-container" style="display: none;">
                <button
                    id="cast-button"
                    class="flex items-center gap-2 rounded bg-white/10 hover:bg-white/20 px-3 py-1.5 text-xs font-medium text-white transition-colors"
                    title="Cast to Chromecast"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1 18v3h3c0-1.66-1.34-3-3-3zm0-4v2c2.76 0 5 2.24 5 5h2c0-3.87-3.13-7-7-7zm0-4v2c4.97 0 9 4.03 9 9h2c0-6.08-4.93-11-11-11zm20-7H3c-1.1 0-2 .9-2 2v3h2V5h18v14h-7v2h7c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                    </svg>
                    <span id="cast-button-label">Cast</span>
                </button>
            </div>
        </header>

        <section class="relative flex-1 overflow-hidden group">
            <video id="popout-player" class="h-full w-full" controls autoplay preload="metadata"
                data-url="{{ $streamUrl }}"
                data-format="{{ $streamFormat }}"
                data-cast-url="{{ $castUrl }}"
                data-cast-format="{{ $castFormat }}"
                data-content-type="{{ $contentType }}"
                data-stream-id="{{ $streamId }}"
                data-playlist-id="{{ $playlistId }}"
                data-series-id="{{ $seriesId }}"
                data-season-number="{{ $seasonNumber }}"
                data-cast-unavailable-reason="{{ $castUnavailableReason ?? '' }}"
            >
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
                    <div id="popout-player-error-icon" class="mx-auto mb-2 flex justify-center">
                        <svg class="h-8 w-8 text-red-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m9.303 3.376c.866 1.5-.217 3.374-1.949 3.374H4.646c-1.732 0-2.815-1.874-1.949-3.374L10.051 3.374c.866-1.5 3.032-1.5 3.898 0l7.354 12.752ZM12 16.5h.008v.008H12V16.5Z" />
                        </svg>
                    </div>
                    <h2 id="popout-player-error-title" class="text-lg font-semibold">Playback Error</h2>
                    <p id="popout-player-error-message" class="mt-2 text-sm text-white/75">Unable to load the stream.
                    </p>
                    <button id="popout-player-error-retry" type="button" onclick="retryStream('popout-player')"
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

            <div class="absolute top-2 left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                <button type="button" onclick="toggleStreamDetails('popout-player')"
                    class="rounded bg-black/75 hover:bg-black/90 px-2 py-1 text-xs text-white transition-colors"
                    title="Toggle Stream Details">
                    <x-heroicon-o-information-circle class="w-4 h-4" />
                </button>
            </div>

            <!-- Resume Prompt (VOD / Episode) -->
            <div
                id="popout-player-resume"
                class="absolute bottom-14 left-0 right-0 flex justify-center px-4 hidden z-20"
            >
                <div class="bg-gray-900/95 text-white rounded-lg px-4 py-2 flex items-center gap-3 shadow-xl text-sm max-w-sm">
                    <x-heroicon-o-clock class="w-4 h-4 text-blue-400 flex-shrink-0" />
                    <span id="popout-player-resume-time" class="flex-1">Resume from 0:00</span>
                    <button
                        type="button"
                        onclick="document.getElementById('popout-player')._streamPlayer?.resumeFromSaved()"
                        class="px-3 py-1 bg-blue-600 hover:bg-blue-700 rounded text-xs transition-colors flex-shrink-0"
                    >Resume</button>
                    <button
                        type="button"
                        onclick="document.getElementById('popout-player')._streamPlayer?.startOver()"
                        class="text-gray-400 hover:text-white transition-colors flex-shrink-0"
                        title="Start from beginning"
                    >
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

            window.addEventListener('beforeunload', () => {
                if (typeof player.cleanup === 'function') {
                    player.cleanup();
                }
            });

            window.addEventListener('pagehide', () => {
                if (typeof player.cleanup === 'function') {
                    player.cleanup();
                }
            });

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    videoElement.pause();
                } else if (document.visibilityState === 'visible') {
                    videoElement.play().catch(() => {});
                }
            });
        });

        // Chromecast support for the popout player (no Alpine dependency)
        (function() {
            let castSession = null;
            let isCasting = false;

            const videoElement = document.getElementById('popout-player');
            const container = document.getElementById('cast-button-container');
            const btn = document.getElementById('cast-button');
            const label = document.getElementById('cast-button-label');

            function getMimeType(format, url) {
                const f = (format || '').toLowerCase();
                if (f === 'hls' || f === 'm3u8' || (url && url.includes('.m3u8'))) {
                    return 'application/vnd.apple.mpegurl';
                }
                if (f === 'ts' || f === 'mpegts') {
                    return 'video/mp2t';
                }
                return 'video/mp4';
            }

            function toAbsoluteUrl(url) {
                if (!url || url.startsWith('http://') || url.startsWith('https://')) {
                    return url;
                }
                return window.location.origin + (url.startsWith('/') ? '' : '/') + url;
            }

            function updateButton() {
                if (isCasting) {
                    btn.classList.remove('bg-white/10', 'hover:bg-white/20');
                    btn.classList.add('bg-blue-600', 'hover:bg-blue-500');
                    btn.title = 'Stop casting';
                    label.textContent = 'Stop Cast';
                } else {
                    btn.classList.remove('bg-blue-600', 'hover:bg-blue-500');
                    btn.classList.add('bg-white/10', 'hover:bg-white/20');
                    btn.title = 'Cast to Chromecast';
                    label.textContent = 'Cast';
                }
            }

            function initCast() {
                if (!window.cast || !window.cast.framework) {
                    return;
                }

                const context = cast.framework.CastContext.getInstance();
                context.setOptions({
                    receiverApplicationId: chrome.cast.media.DEFAULT_MEDIA_RECEIVER_APP_ID,
                    autoJoinPolicy: chrome.cast.AutoJoinPolicy.ORIGIN_SCOPED,
                });

                container.style.display = '';

                // Disable the cast button if no HLS profile is available
                const castUnavailableReason = videoElement.dataset.castUnavailableReason || '';
                const hasCastUrl = !!(videoElement.dataset.castUrl || @json($castUrl));
                if (!hasCastUrl && castUnavailableReason) {
                    btn.disabled = true;
                    btn.classList.add('opacity-40', 'cursor-not-allowed');
                    btn.classList.remove('hover:bg-white/20');
                    btn.title = castUnavailableReason;
                    label.textContent = castUnavailableReason;
                }

                context.addEventListener(
                    cast.framework.CastContextEventType.CAST_STATE_CHANGED,
                    (event) => {
                        console.log('[PopoutCast] CAST_STATE_CHANGED', {
                            castState: event.castState,
                            previousCastState: event.previousCastState,
                        });

                        if (event.castState === cast.framework.CastState.NOT_CONNECTED) {
                            handleCastEnded();
                        }
                    }
                );

                context.addEventListener(
                    cast.framework.CastContextEventType.SESSION_STATE_CHANGED,
                    (event) => {
                        console.log('[PopoutCast] SESSION_STATE_CHANGED', {
                            sessionState: event.sessionState,
                            previousSessionState: event.previousSessionState,
                            errorCode: event.errorCode ?? null,
                        });

                        if (event.sessionState === cast.framework.SessionState.SESSION_STARTED
                            || event.sessionState === cast.framework.SessionState.SESSION_RESUMED) {
                            const session = context.getCurrentSession();

                            console.log('[PopoutCast] Active session', {
                                sessionId: session?.getSessionId?.() ?? null,
                                receiverFriendlyName: session?.getCastDevice?.().friendlyName ?? null,
                            });
                        }

                        if (event.sessionState === cast.framework.SessionState.SESSION_ENDED) {
                            handleCastEnded();
                        }
                    }
                );
            }

            function setLocalPlaybackSuspendedForCast(suspended) {
                if (!videoElement) {
                    return;
                }

                if (suspended) {
                    videoElement.dataset.localPlaybackSuspendedForCast = 'true';
                } else {
                    delete videoElement.dataset.localPlaybackSuspendedForCast;
                }
            }

            function showCastingState() {
                const loadingEl = document.getElementById('popout-player-loading');
                const errorEl = document.getElementById('popout-player-error');
                const errorMessageEl = document.getElementById('popout-player-error-message');
                const errorTitleEl = document.getElementById('popout-player-error-title');
                const retryButton = document.getElementById('popout-player-error-retry');
                const errorIcon = document.getElementById('popout-player-error-icon');

                if (loadingEl) {
                    loadingEl.style.display = 'none';
                }
                if (errorEl) {
                    errorEl.classList.remove('hidden');
                    errorEl.style.display = 'flex';
                }
                if (errorTitleEl) {
                    errorTitleEl.textContent = 'Casting to your device';
                }
                if (errorMessageEl) {
                    errorMessageEl.textContent = 'Playback has moved to Chromecast. Stop casting to resume playback here.';
                }
                if (retryButton) {
                    retryButton.classList.add('hidden');
                }
                if (errorIcon) {
                    errorIcon.innerHTML = '<svg class="h-8 w-8 text-blue-400" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M1 18v3h3c0-1.66-1.34-3-3-3zm0-4v2c2.76 0 5 2.24 5 5h2c0-3.87-3.13-7-7-7zm0-4v2c4.97 0 9 4.03 9 9h2c0-6.08-4.93-11-11-11zm20-7H3c-1.1 0-2 .9-2 2v3h2V5h18v14h-7v2h7c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>';
                }
            }

            function clearCastingState() {
                const errorEl = document.getElementById('popout-player-error');
                const errorMessageEl = document.getElementById('popout-player-error-message');
                const errorTitleEl = document.getElementById('popout-player-error-title');
                const retryButton = document.getElementById('popout-player-error-retry');
                const errorIcon = document.getElementById('popout-player-error-icon');

                if (errorEl) {
                    errorEl.classList.add('hidden');
                    errorEl.style.display = 'none';
                }
                if (errorTitleEl) {
                    errorTitleEl.textContent = 'Playback Error';
                }
                if (errorMessageEl) {
                    errorMessageEl.textContent = 'Unable to load the stream.';
                }
                if (retryButton) {
                    retryButton.classList.remove('hidden');
                }
                if (errorIcon) {
                    errorIcon.innerHTML = '<svg class="h-8 w-8 text-red-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m9.303 3.376c.866 1.5-.217 3.374-1.949 3.374H4.646c-1.732 0-2.815-1.874-1.949-3.374L10.051 3.374c.866-1.5 3.032-1.5 3.898 0l7.354 12.752ZM12 16.5h.008v.008H12V16.5Z" /></svg>';
                }
            }

            function stopLocalPlayer() {
                const video = document.getElementById('popout-player');
                if (video && video._streamPlayer) {
                    video._streamPlayer.cleanup();
                }
            }

            function resumeLocalPlayer() {
                const video = document.getElementById('popout-player');
                if (video && window.streamPlayer) {
                    const sp = window.streamPlayer();
                    sp.initPlayer(
                        @json($streamUrl),
                        @json($streamFormat),
                        'popout-player'
                    );
                }
            }

            async function startCast() {
                const context = cast.framework.CastContext.getInstance();
                await context.requestSession();

                castSession = context.getCurrentSession();
                if (!castSession) {
                    return;
                }

                const castUrl = videoElement.dataset.castUrl || @json($castUrl);
                const castFormat = videoElement.dataset.castFormat || @json($castFormat);

                if (!castUrl) {
                    console.warn('[PopoutCast] No cast-safe URL available', {
                        title: @json($channelTitle),
                        streamUrl: @json($streamUrl),
                    });
                    return;
                }

                // Stop local playback to free the proxy connection
                setLocalPlaybackSuspendedForCast(true);
                stopLocalPlayer();
                showCastingState();

                const url = toAbsoluteUrl(castUrl);
                const resolvedUrl = url;
                const format = castFormat;
                const contentType = getMimeType(format, resolvedUrl);

                const mediaInfo = new chrome.cast.media.MediaInfo(resolvedUrl, contentType);
                mediaInfo.streamType = (@json($contentType) === 'vod' || @json($contentType) === 'episode')
                    ? chrome.cast.media.StreamType.BUFFERED
                    : chrome.cast.media.StreamType.LIVE;
                mediaInfo.customData = {
                    debug: {
                        requestedUrl: resolvedUrl,
                        originalUrl: castUrl,
                        resolvedUrl,
                        format,
                    },
                };

                console.log('[PopoutCast] Preparing cast media', {
                    castUrl,
                    url,
                    resolvedUrl,
                    format,
                    contentType,
                    streamType: mediaInfo.streamType,
                    title: @json($channelTitle),
                });

                const metadata = new chrome.cast.media.GenericMediaMetadata();
                metadata.title = @json($channelTitle);
                @if($channelLogo)
                    metadata.images = [new chrome.cast.Image(toAbsoluteUrl(@json($channelLogo)))];
                @endif
                mediaInfo.metadata = metadata;

                const loadRequest = new chrome.cast.media.LoadRequest(mediaInfo);
                loadRequest.autoplay = true;

                console.log('[PopoutCast] loadMedia request', {
                    contentId: mediaInfo.contentId,
                    contentType: mediaInfo.contentType,
                    streamType: mediaInfo.streamType,
                    autoplay: loadRequest.autoplay,
                    metadata: mediaInfo.metadata,
                    customData: mediaInfo.customData,
                });

                await castSession.loadMedia(loadRequest);

                const mediaSession = castSession.getMediaSession();
                console.log('[PopoutCast] loadMedia resolved', {
                    hasMediaSession: Boolean(mediaSession),
                    playerState: mediaSession?.playerState ?? null,
                    idleReason: mediaSession?.idleReason ?? null,
                    media: mediaSession?.media ?? null,
                });

                isCasting = true;
                updateButton();
            }

            function handleCastEnded() {
                const wasCasting = isCasting;
                isCasting = false;
                castSession = null;
                updateButton();
                clearCastingState();
                setLocalPlaybackSuspendedForCast(false);

                // Resume local playback when cast ends
                if (wasCasting) {
                    resumeLocalPlayer();
                }
            }

            function stopCast() {
                const context = cast.framework.CastContext.getInstance();
                const session = context.getCurrentSession();
                if (session) {
                    session.endSession(true);
                }
                handleCastEnded();
            }

            if (btn) {
                btn.addEventListener('click', async () => {
                    try {
                        if (isCasting) {
                            stopCast();
                        } else {
                            await startCast();
                        }
                    } catch (e) {
                        if (e.code !== 'cancel') {
                            console.error('[CastPopout] Error:', e);
                        }
                    }
                });
            }

            // Chain onto any existing callback (cast-manager.js may have set one)
            const _prevCastCb = window['__onGCastApiAvailable'];
            window['__onGCastApiAvailable'] = (isAvailable) => {
                if (typeof _prevCastCb === 'function') {
                    _prevCastCb(isAvailable);
                }
                if (isAvailable) {
                    initCast();
                }
            };

            // SDK may have loaded already
            if (window.cast && window.cast.framework) {
                initCast();
            }
        })();
    </script>
</body>

</html>