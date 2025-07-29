// Global stream player functionality
function streamPlayer() {
    return {
        player: null,
        hls: null,
        mpegts: null,
        streamMetadata: {
            codec: null,
            resolution: null,
            audioCodec: null,
            audioChannels: null,
            bitrate: null,
            framerate: null,
            profile: null,
            level: null
        },
        
        initPlayer(url, format, playerId) {
            console.log('initPlayer called with:', { url, format, playerId });
            
            const video = document.getElementById(playerId);
            const loadingEl = document.getElementById(playerId + '-loading');
            const errorEl = document.getElementById(playerId + '-error');
            const statusEl = document.getElementById(playerId + '-status');
            
            console.log('DOM elements found:', { 
                video: !!video, 
                loadingEl: !!loadingEl, 
                errorEl: !!errorEl, 
                statusEl: !!statusEl 
            });
            
            if (!video) {
                console.error('Video element not found:', playerId);
                return;
            }
            
            if (!url) {
                console.error('No stream URL provided');
                this.showError(playerId, 'No stream URL provided');
                return;
            }
            
            console.log('Starting player initialization...');
            
            // Store reference to video element for cleanup
            this.player = video;
            
            // Reset error counters
            this.fragmentErrorCount = 0;
            
            // Clean up any existing players
            this.cleanup();
            
            // Update status
            !!statusEl && (statusEl.textContent = 'Connecting...');
            !!loadingEl && (loadingEl.style.display = 'flex');
            !!errorEl && (errorEl.style.display = 'none');
            
            try {
                if (format === 'hls' || url.includes('.m3u8')) {
                    console.log('Initializing HLS player');
                    this.initHlsPlayer(video, url, playerId);
                } else if (format === 'ts' || format === 'mpegts') {
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
            if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                console.log('Creating HLS player with configuration...');
                this.hls = new Hls({
                    enableWorker: true,
                    lowLatencyMode: true,
                    backBufferLength: 90,
                    maxBufferLength: 30,
                    maxMaxBufferLength: 600,
                    maxBufferSize: 60 * 1000 * 1000,
                    maxBufferHole: 0.5,
                    // Add debug logging
                    debug: false,
                    // Add retry and timeout configurations
                    manifestLoadingTimeOut: 10000,
                    manifestLoadingMaxRetry: 3,
                    manifestLoadingRetryDelay: 1000,
                    levelLoadingTimeOut: 10000,
                    levelLoadingMaxRetry: 4,
                    levelLoadingRetryDelay: 1000,
                    fragLoadingTimeOut: 20000,
                    fragLoadingMaxRetry: 6,
                    fragLoadingRetryDelay: 1000,
                    // Add CORS configuration
                    xhrSetup: function(xhr, url) {
                        console.log('HLS XHR setup for:', url);
                        // Add any necessary headers here
                        xhr.withCredentials = false;
                    }
                });
                
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
                        switch(data.type) {
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
                video.src = url;
                this.setupNativeEvents(video, playerId);
            } else {
                throw new Error('HLS is not supported in this browser');
            }
        },
        
        initMpegTsPlayer(video, url, playerId) {
            console.log('MPEG-TS libraries available:', typeof mpegts !== 'undefined', mpegts?.getFeatureList().mseLivePlayback);
            
            // Set some defaults for MPEG-TS streams
            this.streamMetadata.codec = 'H.264';
            this.streamMetadata.audioCodec = 'AAC';
            this.streamMetadata.audioChannels = '2.0';
            this.updateStreamDetails(playerId);
            
            if (typeof mpegts !== 'undefined' && mpegts.getFeatureList().mseLivePlayback) {
                console.log('Creating MPEG-TS player...');
                this.mpegts = mpegts.createPlayer({
                    type: 'mpegts',
                    url: url,
                    isLive: true
                });
                
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
                
                // Try to collect additional metadata once playing
                setTimeout(() => {
                    this.collectVideoMetadata(video, playerId);
                }, 1000); // Give it a second to establish the stream
            });

            video.addEventListener('progress', () => {
                // Try to collect metadata as data loads
                if (video.buffered.length > 0 && !this.streamMetadata.codec) {
                    this.collectVideoMetadata(video, playerId);
                }
            });
            
            video.addEventListener('error', (e) => {
                console.error('Native video error:', e, video.error);
                let errorMessage = 'Playback failed';
                if (video.error) {
                    switch(video.error.code) {
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
            
            if (loadingEl) loadingEl.style.display = 'none';
            if (errorEl) errorEl.style.display = 'flex';
            if (errorMessageEl) errorMessageEl.textContent = message;
            
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

            // Try to get audio tracks info
            if (video.audioTracks && video.audioTracks.length > 0) {
                const audioTrack = video.audioTracks[0];
                if (audioTrack.language) {
                    this.streamMetadata.audioCodec = audioTrack.language;
                }
                if (audioTrack.label) {
                    // Try to parse codec from label
                    const audioCodecMatch = audioTrack.label.match(/(aac|mp3|ac3|dts|pcm)/i);
                    if (audioCodecMatch) {
                        this.streamMetadata.audioCodec = audioCodecMatch[1].toUpperCase();
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

            // Try to detect audio channels from the audio context if available
            if (!this.streamMetadata.audioChannels && video.audioTracks && video.audioTracks.length > 0) {
                // Default to stereo for most streams
                this.streamMetadata.audioChannels = '2.0';
            }

            this.updateStreamDetails(playerId);
        },
        
        cleanup() {
            console.log('Cleaning up stream player...');
            
            // Reset stream metadata
            this.streamMetadata = {
                codec: null,
                resolution: null,
                audioCodec: null,
                audioChannels: null,
                bitrate: null,
                framerate: null,
                profile: null,
                level: null
            };
            
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
                    this.player.src = '';
                    this.player.load(); // This will stop any ongoing loading/streaming
                } catch (error) {
                    console.warn('Error cleaning up video element:', error);
                }
            }
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

// Make streamPlayer function globally accessible
window.streamPlayer = streamPlayer;

// Make retryStream function globally accessible
window.retryStream = retryStream;
