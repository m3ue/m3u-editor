// Global stream player functionality
function streamPlayer() {
    return {
        player: null,
        hls: null,
        mpegts: null,
        streamMetadata: {
            format: null,
            codec: null,
            resolution: null,
            audioCodec: null,
            audioChannels: null,
            bitrate: null,
            framerate: null,
            profile: null,
            level: null
        },
        availableAudioTracks: [],
        selectedAudioTrack: null,

        // ── Watch Progress ────────────────────────────────────────────────
        progressConfig: null,   // { contentType, streamId, playlistId, seriesId, seasonNumber }
        _progressTimer: null,
        _lastSavedPosition: -1,
        _resumePosition: 0,
        _liveReported: false,

        _getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        },

        _initProgress() {
            if (!this.player) return;
            const el = this.player;
            const contentType = el.dataset.contentType;
            const streamId = parseInt(el.dataset.streamId);
            if (!contentType || !streamId) return;

            this.progressConfig = {
                contentType,
                streamId,
                playlistId: parseInt(el.dataset.playlistId) || null,
                seriesId: el.dataset.seriesId ? parseInt(el.dataset.seriesId) : null,
                seasonNumber: el.dataset.seasonNumber ? parseInt(el.dataset.seasonNumber) : null,
            };

            if (contentType === 'live') {
                this._reportLiveTuneIn();
            } else {
                this._fetchProgress();
            }
        },

        async _reportLiveTuneIn() {
            if (this._liveReported || !this.progressConfig) return;
            this._liveReported = true;
            try {
                await fetch('/api/watch-progress', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this._getCsrfToken() },
                    body: JSON.stringify({
                        content_type: 'live',
                        stream_id: this.progressConfig.streamId,
                        playlist_id: this.progressConfig.playlistId,
                    }),
                });
            } catch (e) {
                console.warn('[WatchProgress] Failed to report live tune-in:', e);
            }
        },

        async _fetchProgress() {
            if (!this.progressConfig) return;
            try {
                const params = new URLSearchParams({
                    content_type: this.progressConfig.contentType,
                    stream_id: this.progressConfig.streamId,
                    playlist_id: this.progressConfig.playlistId ?? '',
                });
                const res = await fetch(`/api/watch-progress?${params}`, {
                    headers: { 'X-CSRF-TOKEN': this._getCsrfToken() },
                });
                if (res.ok) {
                    const data = await res.json();
                    if (data && data.position_seconds > 30 && !data.completed) {
                        this._resumePosition = data.position_seconds;
                        this._showResumePrompt(data.position_seconds);
                    }
                }
            } catch (e) {
                console.warn('[WatchProgress] Failed to fetch progress:', e);
            }
        },

        _startProgressTimer() {
            if (this._progressTimer || !this.progressConfig || this.progressConfig.contentType === 'live') return;
            this._progressTimer = setInterval(() => this._saveProgress(), 15000);
        },

        _stopProgressTimer() {
            if (this._progressTimer) {
                clearInterval(this._progressTimer);
                this._progressTimer = null;
            }
        },

        async _saveProgress(force = false, positionOverride = null) {
            if (!this.progressConfig || !this.player || this.progressConfig.contentType === 'live') return;
            const position = positionOverride !== null ? positionOverride : Math.floor(this.player.currentTime || 0);
            const duration = isFinite(this.player.duration) ? Math.floor(this.player.duration) : null;
            if (!force && Math.abs(position - this._lastSavedPosition) < 5) return;
            this._lastSavedPosition = position;
            try {
                await fetch('/api/watch-progress', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this._getCsrfToken() },
                    body: JSON.stringify({
                        content_type: this.progressConfig.contentType,
                        stream_id: this.progressConfig.streamId,
                        playlist_id: this.progressConfig.playlistId,
                        series_id: this.progressConfig.seriesId,
                        season_number: this.progressConfig.seasonNumber,
                        position_seconds: position,
                        duration_seconds: duration,
                    }),
                });
            } catch (e) {
                console.warn('[WatchProgress] Failed to save progress:', e);
            }
        },

        _showResumePrompt(positionSeconds) {
            const playerId = this.player?.id;
            if (!playerId) return;
            const el = document.getElementById(playerId + '-resume');
            const timeEl = document.getElementById(playerId + '-resume-time');
            if (el) {
                if (timeEl) timeEl.textContent = `Resume from ${this.formatSeconds(positionSeconds)}`;
                el.classList.remove('hidden');
                // Auto-dismiss after 8 seconds if no interaction
                setTimeout(() => el.classList.add('hidden'), 8000);
            }
        },

        hideResumePrompt() {
            const el = document.getElementById((this.player?.id ?? '') + '-resume');
            if (el) el.classList.add('hidden');
        },

        resumeFromSaved() {
            if (this.player && this._resumePosition > 0) {
                this.player.currentTime = this._resumePosition;
                this.player.play();
                this._saveProgress(true, this._resumePosition);
            }
            this.hideResumePrompt();
        },

        startOver() {
            this._resumePosition = 0;
            this.hideResumePrompt();
        },

        formatSeconds(seconds) {
            if (seconds <= 0) return '0:00';
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = seconds % 60;
            const mm = String(m).padStart(2, '0');
            const ss = String(s).padStart(2, '0');
            return h > 0 ? `${h}:${mm}:${ss}` : `${m}:${ss}`;
        },
        // ─────────────────────────────────────────────────────────────────

        _isLocalPlaybackSuspendedForCast(playerId = null) {
            const player = playerId ? document.getElementById(playerId) : this.player;
            return player?.dataset?.localPlaybackSuspendedForCast === 'true';
        },

        initPlayer(url, format, playerId) {
            if (!url) {
                return
            }

            console.log('initPlayer called with:', { url, format, playerId });
            const video = document.getElementById(playerId);
            const loadingEl = document.getElementById(playerId + '-loading');
            const errorEl = document.getElementById(playerId + '-error');
            const statusEl = document.getElementById(playerId + '-status');

            if (!video) {
                console.error('Video element not found:', playerId);
                return;
            }

            // Clean up any existing players before binding the new video element
            this.cleanup();

            // Store reference to video element for cleanup
            this.player = video;

            // Store reference to this stream player instance on the video element
            video._streamPlayer = this;

            // Reset error counters
            this.fragmentErrorCount = 0;

            // Initialise progress tracking from data attributes
            this._initProgress();

            // Update status
            !!statusEl && (statusEl.textContent = 'Connecting...');
            !!loadingEl && (loadingEl.style.display = 'flex');
            !!errorEl && (errorEl.style.display = 'none');
            try {
                // Use the explicit format parameter as authoritative. Only fall back to
                // URL sniffing when no format is provided — the URL extension is an
                // Xtream routing concern and may not reflect the actual output format
                // (e.g. direct-proxied episodes use .m3u8 in the path but deliver raw video).
                const effectiveFormat = format || (url.includes('.m3u8') ? 'm3u8' : '');

                if (effectiveFormat === 'hls' || effectiveFormat === 'm3u8') {
                    console.log('Initializing HLS player');
                    this.initHlsPlayer(video, url, playerId);
                } else if (effectiveFormat === 'ts' || effectiveFormat === 'mpegts') {
                    console.log('Initializing MPEG-TS player');
                    this.initMpegTsPlayer(video, url, playerId);
                } else {
                    console.log('Initializing native player');
                    this.initNativePlayer(video, url, playerId);
                }
            } catch (error) {
                console.error('Error initializing player:', error);
                this.showError(playerId, error.message);
            }
        },

        initHlsPlayer(video, url, playerId) {
            // Set stream format
            this.streamMetadata.format = 'HLS';

            const contentType = video.dataset.contentType || '';
            const isLive = contentType === 'live';

            if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                console.log('Creating HLS player with configuration...', { contentType, isLive });
                this.hls = new Hls({
                    enableWorker: true,
                    lowLatencyMode: isLive,
                    backBufferLength: isLive ? 90 : 30,
                    maxBufferLength: isLive ? 30 : 60,
                    maxMaxBufferLength: isLive ? 600 : 120,
                    maxBufferSize: 60 * 1000 * 1000,
                    maxBufferHole: 0.5,
                    startPosition: -1,
                    liveSyncDurationCount: isLive ? 3 : undefined,
                    liveMaxLatencyDurationCount: isLive ? 6 : undefined,
                    liveDurationInfinity: isLive,
                    liveBackBufferLength: isLive ? 60 : undefined,
                    debug: false,
                    manifestLoadingTimeOut: isLive ? 10000 : 15000,
                    manifestLoadingMaxRetry: isLive ? 3 : 4,
                    manifestLoadingRetryDelay: isLive ? 1000 : 1500,
                    levelLoadingTimeOut: isLive ? 10000 : 15000,
                    levelLoadingMaxRetry: 4,
                    levelLoadingRetryDelay: isLive ? 1000 : 1500,
                    fragLoadingTimeOut: isLive ? 20000 : 30000,
                    fragLoadingMaxRetry: 6,
                    fragLoadingRetryDelay: isLive ? 1000 : 1500,
                    xhrSetup: function (xhr, url) {
                        console.log('HLS XHR setup for:', url);
                        xhr.withCredentials = false;
                    }
                });

                // Load source and attach media
                this.hls.loadSource(url);
                this.hls.attachMedia(video);

                this.hls.on(Hls.Events.MANIFEST_PARSED, () => {
                    console.log('HLS manifest parsed successfully');

                    // Collect HLS metadata
                    if (this.hls.levels && this.hls.levels.length > 0) {
                        const level = this.hls.levels[this.hls.currentLevel] || this.hls.levels[0];
                        if (level) {
                            this.streamMetadata.resolution = `${level.width}x${level.height}`;
                            this.streamMetadata.bitrate = level.bitrate;
                            this.streamMetadata.framerate = level.frameRate;

                            // Parse codec info
                            if (level.codecName) {
                                this.streamMetadata.codec = level.codecName;
                            } else if (level.videoCodec) {
                                this.streamMetadata.codec = level.videoCodec.split('.')[0];
                            }

                            if (level.audioCodec) {
                                this.streamMetadata.audioCodec = level.audioCodec.split('.')[0];
                            }
                        }
                    }

                    this.hideLoading(playerId);
                    this.updateStatus(playerId, 'Connected');
                    this.updateStreamDetails(playerId);
                });

                // Also set up native events for Safari HLS
                this.setupNativeEvents(video, playerId);

                // Reset error counter on successful fragment load
                this.hls.on(Hls.Events.FRAG_LOADED, () => {
                    this.fragmentErrorCount = 0;
                });

                this.hls.on(Hls.Events.ERROR, (event, data) => {
                    console.error('HLS Error:', data);

                    // Check for authentication/authorization errors (403, 401)
                    const isAuthError = data.response && (data.response.code === 403 || data.response.code === 401);
                    const isFragLoadError = data.details && data.details.includes('FRAG_LOAD_ERROR');

                    // If we get auth errors on fragment loading, immediately fall back to native
                    if (isAuthError && isFragLoadError) {
                        console.log('HLS Authentication error on fragments, falling back to native player immediately');
                        this.cleanup();
                        this.initNativePlayer(video, url, playerId);
                        return;
                    }

                    // Handle different types of errors
                    if (data.fatal) {
                        console.log('Fatal HLS error, attempting recovery or fallback');
                        switch (data.type) {
                            case Hls.ErrorTypes.NETWORK_ERROR:
                                console.log('HLS Network error, trying to recover...');
                                this.hls.startLoad();
                                break;
                            case Hls.ErrorTypes.MEDIA_ERROR:
                                console.log('HLS Media error, trying to recover...');
                                this.hls.recoverMediaError();
                                break;
                            default:
                                console.log('HLS Unrecoverable error, falling back to native player');
                                this.cleanup();
                                this.initNativePlayer(video, url, playerId);
                                break;
                        }
                    } else {
                        console.warn('Non-fatal HLS error:', data.details);
                        // For segment loading errors, let's show the specific error
                        if (data.details && data.details.includes('FRAG_LOAD_ERROR')) {
                            // If we've had multiple fragment errors, fall back
                            if (!this.fragmentErrorCount) this.fragmentErrorCount = 0;
                            this.fragmentErrorCount++;

                            if (this.fragmentErrorCount >= 3) {
                                console.log('Multiple fragment errors, falling back to native player');
                                this.cleanup();
                                this.initNativePlayer(video, url, playerId);
                                return;
                            }

                            this.showError(playerId, `Segment loading failed: ${data.response?.code || 'Network error'}`);
                        }
                    }
                });

                // Add more event listeners for debugging
                this.hls.on(Hls.Events.FRAG_LOAD_ERROR, (event, data) => {
                    console.error('HLS Fragment load error:', data);
                    console.error('Failed URL:', data.frag?.url);
                    console.error('Response:', data.response);
                });

                this.hls.on(Hls.Events.LEVEL_LOADED, (event, data) => {
                    console.log('HLS Level loaded:', data.level, 'URL:', data.details?.url);
                });

                this.hls.on(Hls.Events.LEVEL_SWITCHED, (event, data) => {
                    console.log('HLS Level switched to:', data.level);

                    // Update metadata when level changes
                    if (this.hls.levels && this.hls.levels[data.level]) {
                        const level = this.hls.levels[data.level];
                        this.streamMetadata.resolution = `${level.width}x${level.height}`;
                        this.streamMetadata.bitrate = level.bitrate;
                        this.streamMetadata.framerate = level.frameRate;
                        this.updateStreamDetails(playerId);
                    }
                });

            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                console.log('Using Safari native HLS support');
                this.streamMetadata.format = 'HLS (Native)';
                video.src = url;
                this.setupNativeEvents(video, playerId);
            } else {
                throw new Error('HLS is not supported in this browser');
            }
        },

        initMpegTsPlayer(video, url, playerId) {
            console.log('MPEG-TS libraries available:', typeof mpegts !== 'undefined', mpegts?.getFeatureList().mseLivePlayback);

            const contentType = video.dataset.contentType || '';
            const isLive = contentType === 'live';

            // Set stream format
            this.streamMetadata.format = 'MPEG-TS';

            // Set some defaults for MPEG-TS streams
            this.streamMetadata.codec = '...';
            this.streamMetadata.audioCodec = '...';
            this.streamMetadata.audioChannels = '...';
            this.updateStreamDetails(playerId);

            if (typeof mpegts !== 'undefined' && mpegts.getFeatureList().mseLivePlayback) {
                console.log('Creating MPEG-TS player...', { contentType, isLive });
                this.mpegts = mpegts.createPlayer({
                    type: 'mpegts',
                    url: url,
                    isLive,
                    enableWorker: true,
                    enableStashBuffer: isLive,
                    liveBufferLatencyChasing: isLive,
                    liveSync: isLive,
                    cors: true,
                    autoCleanupSourceBuffer: true,
                    autoCleanupMaxBackwardDuration: isLive ? 10 : 30,
                    autoCleanupMinBackwardDuration: isLive ? 5 : 15,
                    reuseRedirectedURL: true,
                });

                // Attach media element and load
                this.mpegts.attachMediaElement(video);
                this.mpegts.load();

                this.mpegts.on(mpegts.Events.METADATA_ARRIVED, (metadata) => {
                    // Collect MPEG-TS metadata - override defaults with actual values
                    if (metadata.videoCodec) {
                        this.streamMetadata.codec = metadata.videoCodec;
                    }
                    if (metadata.audioCodec) {
                        this.streamMetadata.audioCodec = metadata.audioCodec;
                    }
                    if (metadata.width && metadata.height) {
                        this.streamMetadata.resolution = `${metadata.width}x${metadata.height}`;
                    }
                    if (metadata.videoBitrate) {
                        this.streamMetadata.bitrate = metadata.videoBitrate;
                    }
                    if (metadata.frameRate) {
                        this.streamMetadata.framerate = metadata.frameRate;
                    }
                    if (metadata.audioChannels) {
                        this.streamMetadata.audioChannels = metadata.audioChannels;
                    }

                    this.hideLoading(playerId);
                    this.updateStatus(playerId, 'Connected');
                    this.updateStreamDetails(playerId);
                });

                this.mpegts.on(mpegts.Events.MEDIA_INFO, (mediaInfo) => {
                    // Additional metadata from media info
                    if (mediaInfo.width && mediaInfo.height) {
                        this.streamMetadata.resolution = `${mediaInfo.width}x${mediaInfo.height}`;
                    }
                    if (mediaInfo.videoCodec) {
                        this.streamMetadata.codec = mediaInfo.videoCodec;
                    }
                    if (mediaInfo.audioCodec) {
                        this.streamMetadata.audioCodec = mediaInfo.audioCodec;
                    }
                    if (mediaInfo.audioChannelCount) {
                        this.streamMetadata.audioChannels = mediaInfo.audioChannelCount === 2 ? '2.0' : mediaInfo.audioChannelCount.toString();
                    }
                    if (mediaInfo.fps) {
                        this.streamMetadata.framerate = mediaInfo.fps;
                    }

                    this.updateStreamDetails(playerId);
                });

                this.mpegts.on(mpegts.Events.ERROR, (type, details, info) => {
                    console.error('MPEGTS Error:', type, details, info);
                    this.showError(playerId, `MPEGTS Error: ${details || 'Unknown error'}`);
                });

                // Also set up native video events as backup
                this.setupNativeEvents(video, playerId);

            } else {
                console.log('MPEG-TS not supported, falling back to native');
                // Fallback to native
                this.initNativePlayer(video, url, playerId);
            }
        },

        initNativePlayer(video, url, playerId) {
            console.log('Initializing native player for:', playerId);

            // Set stream format
            this.streamMetadata.format = 'Native';

            // Configure video element for optimal audio track detection
            video.muted = false;
            video.volume = 0.5;
            video.controls = true;
            video.preload = 'metadata';

            video.src = url;
            this.setupNativeEvents(video, playerId);
        },

        setupNativeEvents(video, playerId) {
            video.addEventListener('loadstart', () => {
                this.updateStatus(playerId, 'Loading...');
            });

            video.addEventListener('loadedmetadata', () => {
                // Collect basic metadata
                if (video.videoWidth && video.videoHeight) {
                    this.streamMetadata.resolution = `${video.videoWidth}x${video.videoHeight}`;
                }

                this.collectVideoMetadata(video, playerId);
                this.hideLoading(playerId);
                this.updateStatus(playerId, 'Ready');
                this.updateStreamDetails(playerId);
            });

            video.addEventListener('loadeddata', () => {
                this.collectVideoMetadata(video, playerId);
            });

            video.addEventListener('canplay', () => {
                this.updateStatus(playerId, 'Ready');
                this.collectVideoMetadata(video, playerId);
            });

            video.addEventListener('playing', () => {
                this.updateStatus(playerId, 'Playing');
                this._startProgressTimer();

                // Try to collect additional metadata once playing
                setTimeout(() => {
                    this.collectVideoMetadata(video, playerId);
                }, 1000); // Give it a second to establish the stream
            });

            video.addEventListener('pause', () => {
                this._saveProgress(true);
            });

            video.addEventListener('ended', () => {
                this._saveProgress(true);
            });

            video.addEventListener('progress', () => {
                // Try to collect metadata as data loads
                if (video.buffered.length > 0 && !this.streamMetadata.codec) {
                    this.collectVideoMetadata(video, playerId);
                }
            });

            video.addEventListener('error', (e) => {
                if (video.error.code === video.error.MEDIA_ELEMENT_ERROR) {
                    return; // Ignore MEDIA_ELEMENT_ERROR which can happen on cleanup
                }
                let errorMessage = 'Playback failed';
                if (video.error) {
                    switch (video.error.code) {
                        case video.error.MEDIA_ERR_ABORTED:
                            errorMessage = 'Playback aborted';
                            break;
                        case video.error.MEDIA_ERR_NETWORK:
                            errorMessage = 'Network error';
                            break;
                        case video.error.MEDIA_ERR_DECODE:
                            errorMessage = 'Decode error';
                            break;
                        case video.error.MEDIA_ERR_SRC_NOT_SUPPORTED:
                            errorMessage = 'Format not supported';
                            break;
                    }
                }
                this.showError(playerId, errorMessage);
            });
        },

        hideLoading(playerId) {
            const loadingEl = document.getElementById(playerId + '-loading');
            if (loadingEl) {
                loadingEl.style.display = 'none';
            }
        },

        showError(playerId, message) {
            const loadingEl = document.getElementById(playerId + '-loading');
            const errorEl = document.getElementById(playerId + '-error');
            const errorMessageEl = document.getElementById(playerId + '-error-message');
            const errorTitleEl = document.getElementById(playerId + '-error-title');
            const retryButtonEl = document.getElementById(playerId + '-error-retry');
            const errorIconEl = document.getElementById(playerId + '-error-icon');
            const isCastingLocallySuspended = this._isLocalPlaybackSuspendedForCast(playerId);

            if (loadingEl) loadingEl.style.display = 'none';
            if (errorEl) errorEl.style.display = 'flex';

            if (isCastingLocallySuspended) {
                if (errorTitleEl) {
                    errorTitleEl.textContent = 'Casting to your device';
                }
                if (errorMessageEl) {
                    errorMessageEl.textContent = 'Playback has moved to Chromecast. Stop casting to resume playback here.';
                }
                if (retryButtonEl) {
                    retryButtonEl.classList.add('hidden');
                }
                if (errorIconEl) {
                    errorIconEl.innerHTML = '<svg class="h-8 w-8 text-blue-400" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M1 18v3h3c0-1.66-1.34-3-3-3zm0-4v2c2.76 0 5 2.24 5 5h2c0-3.87-3.13-7-7-7zm0-4v2c4.97 0 9 4.03 9 9h2c0-6.08-4.93-11-11-11zm20-7H3c-1.1 0-2 .9-2 2v3h2V5h18v14h-7v2h7c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>';
                }
                this.updateStatus(playerId, 'Casting');
                return;
            }

            if (errorTitleEl) {
                errorTitleEl.textContent = 'Playback Error';
            }
            if (errorMessageEl) {
                errorMessageEl.textContent = message;
            }
            if (retryButtonEl) {
                retryButtonEl.classList.remove('hidden');
            }
            if (errorIconEl) {
                errorIconEl.innerHTML = '<svg class="h-8 w-8 text-red-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m9.303 3.376c.866 1.5-.217 3.374-1.949 3.374H4.646c-1.732 0-2.815-1.874-1.949-3.374L10.051 3.374c.866-1.5 3.032-1.5 3.898 0l7.354 12.752ZM12 16.5h.008v.008H12V16.5Z" /></svg>';
            }

            this.updateStatus(playerId, 'Error');
        },

        updateStatus(playerId, status) {
            const statusEl = document.getElementById(playerId + '-status');
            if (statusEl) statusEl.textContent = status;
        },

        updateStreamDetails(playerId) {
            const detailsEl = document.getElementById(playerId + '-details');
            if (!detailsEl) return;

            let detailsHtml = '';

            // Stream Format (always show first)
            if (this.streamMetadata.format) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Stream Format:</span><span class="font-mono font-semibold text-blue-400">${this.streamMetadata.format}</span></div>`;
            }

            if (this.streamMetadata.resolution) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Resolution:</span><span class="font-mono">${this.streamMetadata.resolution}</span></div>`;
            }
            if (this.streamMetadata.codec) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Video Codec:</span><span class="font-mono">${this.streamMetadata.codec}</span></div>`;
            }
            if (this.streamMetadata.audioCodec) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Audio Codec:</span><span class="font-mono">${this.streamMetadata.audioCodec}</span></div>`;
            }
            if (this.streamMetadata.audioChannels) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Audio Channels:</span><span class="font-mono">${this.streamMetadata.audioChannels}</span></div>`;
            }
            if (this.streamMetadata.bitrate) {
                const bitrateKbps = Math.round(this.streamMetadata.bitrate / 1000);
                detailsHtml += `<div class="flex justify-between gap-1"><span>Bitrate:</span><span class="font-mono">${bitrateKbps} kbps</span></div>`;
            }
            if (this.streamMetadata.framerate) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Frame Rate:</span><span class="font-mono">${this.streamMetadata.framerate} fps</span></div>`;
            }
            if (this.streamMetadata.profile) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Profile:</span><span class="font-mono">${this.streamMetadata.profile}</span></div>`;
            }

            if (detailsHtml) {
                detailsEl.innerHTML = detailsHtml;
                detailsEl.style.display = 'block';
            } else {
                detailsEl.innerHTML = '<div class="text-gray-500 dark:text-gray-400 text-sm">Stream details not available</div>';
                detailsEl.style.display = 'block';
            }
        },

        collectVideoMetadata(video, playerId) {
            console.log('Collecting video metadata for:', playerId);

            // Get basic video properties
            if (video.videoWidth && video.videoHeight) {
                this.streamMetadata.resolution = `${video.videoWidth}x${video.videoHeight}`;
            }

            // Try to estimate framerate from video properties
            if (video.getVideoPlaybackQuality) {
                try {
                    const quality = video.getVideoPlaybackQuality();
                    if (quality.totalVideoFrames && quality.creationTime) {
                        const fps = Math.round(quality.totalVideoFrames / (quality.creationTime / 1000));
                        if (fps > 0 && fps < 120) { // Reasonable FPS range
                            this.streamMetadata.framerate = fps;
                        }
                    }
                } catch (e) {
                    // getVideoPlaybackQuality not available
                }
            }

            // Enhanced audio track detection
            this.detectAudioTracks(video, playerId);

            // Try to get video tracks info
            if (video.videoTracks && video.videoTracks.length > 0) {
                const track = video.videoTracks[0];
                if (track.label) {
                    // Parse codec info from track label if available
                    const codecMatch = track.label.match(/(\w+)/);
                    if (codecMatch) {
                        this.streamMetadata.codec = codecMatch[1];
                    }
                }
            }

            // For TS streams, try to infer codec from URL or file extension
            const videoSrc = video.src || video.currentSrc;
            if (videoSrc && !this.streamMetadata.codec) {
                if (videoSrc.includes('.ts') || videoSrc.includes('mpegts')) {
                    this.streamMetadata.codec = 'H.264'; // Most TS streams use H.264
                    if (!this.streamMetadata.audioCodec) {
                        this.streamMetadata.audioCodec = 'AAC'; // Most TS streams use AAC audio
                    }
                }
            }

            // Container-based codec detection
            this.detectCodecFromContainer(video, playerId);

            this.updateStreamDetails(playerId);
        },

        detectAudioTracks(video, playerId) {
            console.log('Detecting audio tracks for:', playerId);

            // Reset audio tracks
            this.availableAudioTracks = [];
            this.selectedAudioTrack = null;

            // Try to get real audio tracks first
            if (video.audioTracks && video.audioTracks.length > 0) {
                console.log('Found real audio tracks:', video.audioTracks.length);

                for (let i = 0; i < video.audioTracks.length; i++) {
                    const track = video.audioTracks[i];
                    console.log(`Audio track ${i}:`, {
                        id: track.id,
                        kind: track.kind,
                        label: track.label,
                        language: track.language,
                        enabled: track.enabled
                    });

                    this.availableAudioTracks.push({
                        index: i,
                        id: track.id,
                        label: track.label || `Track ${i + 1}`,
                        language: track.language || 'unknown',
                        enabled: track.enabled,
                        estimated: false
                    });

                    if (track.enabled) {
                        this.selectedAudioTrack = i;

                        // Try to extract codec info from label
                        if (track.label) {
                            const codecMatch = track.label.match(/(aac|mp3|ac3|dts|pcm|opus|vorbis|flac)/i);
                            if (codecMatch) {
                                this.streamMetadata.audioCodec = codecMatch[1].toUpperCase();
                            }
                        }
                    }
                }
            } else {
                console.log('No real audio tracks found');
            }

            // Default audio channels if we have tracks but no channels
            if (this.availableAudioTracks.length > 0 && !this.streamMetadata.audioChannels) {
                this.streamMetadata.audioChannels = '2.0'; // Stereo default
            }
        },

        detectCodecFromContainer(video, playerId) {
            const videoSrc = video.src || video.currentSrc;
            if (!videoSrc) return;

            const extension = videoSrc.split('.').pop().toLowerCase().split('?')[0];
            console.log('Detecting codec from container extension:', extension);

            switch (extension) {
                case 'mkv':
                    if (!this.streamMetadata.codec) {
                        this.streamMetadata.codec = 'H.264'; // Most common
                    }
                    if (!this.streamMetadata.audioCodec) {
                        this.streamMetadata.audioCodec = 'AAC'; // Common fallback
                    }
                    break;

                case 'mp4':
                case 'm4v':
                    if (!this.streamMetadata.codec) {
                        this.streamMetadata.codec = 'H.264';
                    }
                    if (!this.streamMetadata.audioCodec) {
                        this.streamMetadata.audioCodec = 'AAC';
                    }
                    break;

                case 'webm':
                    if (!this.streamMetadata.codec) {
                        this.streamMetadata.codec = 'VP9';
                    }
                    if (!this.streamMetadata.audioCodec) {
                        this.streamMetadata.audioCodec = 'Opus';
                    }
                    break;

                case 'avi':
                    if (!this.streamMetadata.codec) {
                        this.streamMetadata.codec = 'XVID';
                    }
                    if (!this.streamMetadata.audioCodec) {
                        this.streamMetadata.audioCodec = 'MP3';
                    }
                    break;
            }
        },

        cleanup() {
            console.log('Cleaning up stream player...');

            // Save final progress and stop timer
            this._saveProgress(true);
            this._stopProgressTimer();

            // Reset progress state
            this.progressConfig = null;
            this._lastSavedPosition = -1;
            this._resumePosition = 0;
            this._liveReported = false;

            // Reset stream metadata
            this.streamMetadata = {
                format: null,
                codec: null,
                resolution: null,
                audioCodec: null,
                audioChannels: null,
                bitrate: null,
                framerate: null,
                profile: null,
                level: null
            };

            // Reset audio track data
            this.availableAudioTracks = [];
            this.selectedAudioTrack = null;
            this.baseUrl = null;

            if (this.hls) {
                console.log('Destroying HLS player');
                try {
                    this.hls.destroy();
                } catch (error) {
                    console.warn('Error destroying HLS player:', error);
                }
                this.hls = null;
            }

            if (this.mpegts) {
                console.log('Destroying MPEG-TS player');
                try {
                    this.mpegts.destroy();
                } catch (error) {
                    console.warn('Error destroying MPEG-TS player:', error);
                }
                this.mpegts = null;
            }

            // Also pause and clear any video element that might be playing
            if (this.player && this.player.tagName === 'VIDEO') {
                console.log('Stopping video playback');
                try {
                    this.player.pause();
                    this.player.removeAttribute('src');
                    this.player.load(); // This will stop any ongoing loading/streaming
                    this.player._streamPlayer = null;
                } catch (error) {
                    console.warn('Error cleaning up video element:', error);
                }
            }

            this.player = null;
        }
    };
}

// Global retry function
function retryStream(playerId) {
    const component = document.querySelector(`#${playerId}`).closest('[wire\\:id]');
    if (component) {
        // Re-trigger the player initialization
        const alpineData = Alpine.$data(document.getElementById(playerId));
        if (alpineData && typeof alpineData.initPlayer === 'function') {
            const url = document.getElementById(playerId).getAttribute('data-url');
            const format = document.getElementById(playerId).getAttribute('data-format');
            alpineData.initPlayer(url, format, playerId);
        }
    }
}

// Toggle stream details overlay
function toggleStreamDetails(playerId) {
    const overlay = document.getElementById(playerId + '-details-overlay');
    if (overlay) {
        overlay.classList.toggle('hidden');
    }
}

// Make streamPlayer function globally accessible
window.streamPlayer = streamPlayer;

// Make retryStream function globally accessible
window.retryStream = retryStream;

// Make toggleStreamDetails function globally accessible
window.toggleStreamDetails = toggleStreamDetails;
