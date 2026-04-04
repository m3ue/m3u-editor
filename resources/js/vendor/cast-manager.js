// Chromecast Cast Manager — Alpine.js global store
// Uses Google Cast SDK with Default Media Receiver (CC1AD845)

// Capture Cast SDK readiness at module scope — the SDK may fire this callback
// before Alpine boots, so we store the flag and replay it once the store exists.
//
// On the popout player page Alpine is not loaded, so the inline script there
// handles Cast initialisation directly. We chain onto any existing callback
// rather than overwriting it.
let _castSdkReady = false;

const _previousOnGCastApiAvailable = window['__onGCastApiAvailable'];
window['__onGCastApiAvailable'] = (isAvailable) => {
    // Forward to any previously-registered callback (e.g. popout page inline script)
    if (typeof _previousOnGCastApiAvailable === 'function') {
        _previousOnGCastApiAvailable(isAvailable);
    }

    if (isAvailable) {
        _castSdkReady = true;
        // If the Alpine store already exists, initialise immediately
        if (window.Alpine && Alpine.store('cast')) {
            Alpine.store('cast')._initCastApi();
        }
    }
};

document.addEventListener('alpine:init', () => {
    Alpine.store('cast', {
        isReady: false,
        isAvailable: false,
        isCasting: false,
        currentStreamUrl: null,
        _session: null,
        _mediaSession: null,
        _onStopCallback: null,

        init() {
            // If the Cast SDK was already ready before Alpine booted, init now
            if (_castSdkReady || (window.cast && window.cast.framework)) {
                this._initCastApi();
            }
        },

        _initCastApi() {
            try {
                const context = cast.framework.CastContext.getInstance();

                this.isReady = true;

                context.setOptions({
                    receiverApplicationId: chrome.cast.media.DEFAULT_MEDIA_RECEIVER_APP_ID,
                    autoJoinPolicy: chrome.cast.AutoJoinPolicy.ORIGIN_SCOPED,
                });

                // Listen for cast state changes (device availability)
                context.addEventListener(
                    cast.framework.CastContextEventType.CAST_STATE_CHANGED,
                    (event) => {
                        const state = event.castState;
                        this.isAvailable = state !== cast.framework.CastState.NO_DEVICES_AVAILABLE;

                        console.log('[CastManager] CAST_STATE_CHANGED', {
                            castState: state,
                            previousCastState: event.previousCastState,
                            isAvailable: this.isAvailable,
                        });

                        if (state === cast.framework.CastState.NOT_CONNECTED) {
                            this._handleCastEnded();
                        }
                    }
                );

                // Listen for session state changes
                context.addEventListener(
                    cast.framework.CastContextEventType.SESSION_STATE_CHANGED,
                    (event) => {
                        console.log('[CastManager] SESSION_STATE_CHANGED', {
                            sessionState: event.sessionState,
                            previousSessionState: event.previousSessionState,
                            errorCode: event.errorCode ?? null,
                        });

                        if (event.sessionState === cast.framework.SessionState.SESSION_STARTED
                            || event.sessionState === cast.framework.SessionState.SESSION_RESUMED) {
                            const session = context.getCurrentSession();

                            console.log('[CastManager] Active session', {
                                sessionId: session?.getSessionId?.() ?? null,
                                receiverFriendlyName: session?.getCastDevice?.().friendlyName ?? null,
                            });
                        }

                        if (event.sessionState === cast.framework.SessionState.SESSION_ENDED) {
                            this._handleCastEnded();
                        }
                    }
                );

                console.log('[CastManager] Cast SDK initialised');
            } catch (e) {
                console.warn('[CastManager] Failed to initialise Cast SDK:', e);
            }
        },

        /**
         * Open the Chrome device picker and cast a stream.
         *
         * @param {string}   url            Stream URL (may be relative)
         * @param {string}   format         Stream format: 'hls', 'm3u8', 'ts', 'mpegts'
         * @param {string}   title          Channel/stream title
         * @param {string}   logo           Channel logo URL (optional)
         * @param {Function} onBeforeCast   Called after session is acquired, before media loads — stop local player here
         * @param {Function} onCastStopped  Called when the cast session ends — resume local player here
         */
        async startCast(url, format, title, logo, onBeforeCast, onCastStopped, contentTypeHint = null) {
            if (!window.cast || !window.chrome?.cast) {
                console.warn('[CastManager] Cast SDK not available');
                return;
            }

            try {
                const context = cast.framework.CastContext.getInstance();

                // Request a session (opens device picker if none active)
                await context.requestSession();

                this._session = context.getCurrentSession();
                if (!this._session) {
                    console.warn('[CastManager] No cast session after request');
                    return;
                }

                // Store the resume callback so we can call it when cast ends
                this._onStopCallback = typeof onCastStopped === 'function' ? onCastStopped : null;

                // Stop the local player before loading media on Chromecast
                // This frees the proxy connection so the Chromecast can use it
                if (typeof onBeforeCast === 'function') {
                    onBeforeCast();
                }

                // Build absolute URL so the Chromecast can reach the server
                const absoluteUrl = this._toAbsoluteUrl(url);
                const resolvedUrl = absoluteUrl;

                // Determine MIME type
                const contentType = this._getMimeType(format, resolvedUrl);

                const mediaInfo = new chrome.cast.media.MediaInfo(resolvedUrl, contentType);
                mediaInfo.streamType = this._getStreamType(contentTypeHint, format, url);
                mediaInfo.customData = {
                    debug: {
                        requestedUrl: resolvedUrl,
                        originalUrl: url,
                        resolvedUrl,
                        format,
                    },
                };

                console.log('[CastManager] Preparing cast media', {
                    originalUrl: url,
                    absoluteUrl,
                    resolvedUrl,
                    format,
                    contentType,
                    streamType: mediaInfo.streamType,
                    title: title || 'Stream',
                });

                // Set metadata (title + image)
                const metadata = new chrome.cast.media.GenericMediaMetadata();
                metadata.title = title || 'Stream';
                if (logo) {
                    const absoluteLogo = this._toAbsoluteUrl(logo);
                    metadata.images = [new chrome.cast.Image(absoluteLogo)];
                }
                mediaInfo.metadata = metadata;

                const loadRequest = new chrome.cast.media.LoadRequest(mediaInfo);
                loadRequest.autoplay = true;

                console.log('[CastManager] loadMedia request', {
                    contentId: mediaInfo.contentId,
                    contentType: mediaInfo.contentType,
                    streamType: mediaInfo.streamType,
                    autoplay: loadRequest.autoplay,
                    metadata: mediaInfo.metadata,
                    customData: mediaInfo.customData,
                });

                await this._session.loadMedia(loadRequest);

                this.isCasting = true;
                this.currentStreamUrl = url;
                this._mediaSession = this._session.getMediaSession();

                console.log('[CastManager] loadMedia resolved', {
                    hasMediaSession: Boolean(this._mediaSession),
                    playerState: this._mediaSession?.playerState ?? null,
                    idleReason: this._mediaSession?.idleReason ?? null,
                    media: this._mediaSession?.media ?? null,
                });

                console.log('[CastManager] Now casting:', title);
            } catch (e) {
                // User cancelled the picker or an error occurred
                if (e.code === 'cancel') {
                    console.log('[CastManager] User cancelled cast picker');
                } else {
                    console.error('[CastManager] Cast error:', e);
                }
            }
        },

        /**
         * Stop the current cast session.
         */
        stopCast() {
            try {
                const context = cast.framework.CastContext.getInstance();
                const session = context.getCurrentSession();
                if (session) {
                    session.endSession(true);
                }
            } catch (e) {
                console.warn('[CastManager] Error stopping cast:', e);
            }

            // _handleCastEnded will be called by the session state listener,
            // but call it here too in case the event doesn't fire synchronously
            this._handleCastEnded();
        },

        /**
         * Internal handler when a cast session ends (from any cause).
         * Resets state and invokes the resume callback if one was registered.
         */
        _handleCastEnded() {
            const wasCasting = this.isCasting;
            const callback = this._onStopCallback;

            this.isCasting = false;
            this.currentStreamUrl = null;
            this._session = null;
            this._mediaSession = null;
            this._onStopCallback = null;

            // Resume local playback if we were actually casting
            if (wasCasting && typeof callback === 'function') {
                console.log('[CastManager] Cast ended, resuming local playback');
                callback();
            }
        },

        /**
         * Convert a possibly-relative URL to absolute so the Chromecast device can reach it.
         */
        _toAbsoluteUrl(url) {
            if (!url) {
                return url;
            }
            if (url.startsWith('http://') || url.startsWith('https://')) {
                return url;
            }
            return window.location.origin + (url.startsWith('/') ? '' : '/') + url;
        },

        /**
         * Determine the MIME type for a given stream format.
         */
        _getMimeType(format, url) {
            const f = (format || '').toLowerCase();
            if (f === 'hls' || f === 'm3u8' || (url && url.includes('.m3u8'))) {
                return 'application/vnd.apple.mpegurl';
            }
            if (f === 'ts' || f === 'mpegts') {
                return 'video/mp2t';
            }
            return 'video/mp4';
        },

        /**
         * Check whether the stream should be treated as live.
         */
        _isLiveFormat(format, url) {
            const f = (format || '').toLowerCase();
            // HLS and TS streams from IPTV are typically live
            return f === 'hls' || f === 'm3u8' || f === 'ts' || f === 'mpegts'
                || (url && url.includes('.m3u8'));
        },

        _getStreamType(contentTypeHint, format, url) {
            if (contentTypeHint === 'vod' || contentTypeHint === 'episode') {
                return chrome.cast.media.StreamType.BUFFERED;
            }

            return this._isLiveFormat(format, url)
                ? chrome.cast.media.StreamType.LIVE
                : chrome.cast.media.StreamType.BUFFERED;
        },
    });
});

// Listen for direct-cast requests from table actions (no floating player involved).
// Livewire dispatches this as a browser CustomEvent when the user clicks a table cast button.
window.addEventListener('startDirectCast', (event) => {
    let detail = event.detail;
    if (Array.isArray(detail)) detail = detail[0];

    const store = window.Alpine && Alpine.store('cast');
    if (!store || !store.isReady) {
        console.warn('[CastManager] Cast SDK not ready');
        return;
    }

    const { cast_url, cast_format, title, content_type } = detail;
    store.startCast(
        cast_url,
        cast_format || 'm3u8',
        title || 'Stream',
        null,  // logo
        null,  // onBeforeCast — no local player to stop
        null,  // onCastStopped — no local player to resume
        content_type
    );
});
