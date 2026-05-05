// Multi-Stream Manager Alpine.js Component
function multiStreamManager() {
    return {
        players: [],
        maxPlayers: 0,
        zIndexCounter: 1000,
        _initialized: false,
        _abortController: null,
        _finalized: false,
        _lastClickPos: { x: 0, y: 0 },
        dragState: {
            isDragging: false,
            playerId: null,
            startX: 0,
            startY: 0,
            startLeft: 0,
            startTop: 0
        },
        resizeState: {
            isResizing: false,
            playerId: null,
            startX: 0,
            startY: 0,
            startWidth: 0,
            startHeight: 0
        },

        init() {
            // Only initialize if not already done for this instance
            if (this._initialized) {
                return;
            }

            this._finalized = false;

            // Read max players from container data attribute
            const container = document.querySelector('[data-max-players]');
            if (container) {
                this.maxPlayers = parseInt(container.dataset.maxPlayers, 0) || 0;
            }

            // Abort any previous listeners (safety net in case cleanup wasn't called)
            this._abortController?.abort();
            this._abortController = new AbortController();
            const { signal } = this._abortController;

            // Listen for new stream requests
            window.addEventListener('openFloatingStream', (event) => {
                let detail = event.detail;
                if (Array.isArray(detail)) {
                    detail = detail[0];
                }
                event.stopPropagation(); // Prevent event bubbling
                this.openStream(detail);
            }, { signal });

            // Cleanup on page unload (beforeunload + pagehide for mobile Safari)
            window.addEventListener('beforeunload', () => {
                this.cleanupAllStreams();
            }, { signal });
            window.addEventListener('pagehide', () => {
                this.cleanupAllStreams();
            }, { signal });

            // Cleanup on Livewire SPA navigation
            window.addEventListener('livewire:navigating', () => {
                this.cleanupAllStreams();
            }, { signal });

            // Pause/resume streams on tab visibility change (saves bandwidth on mobile)
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    this.pauseAllStreams();
                } else if (document.visibilityState === 'visible') {
                    this.resumeAllStreams();
                }
            }, { signal });

            // Reposition players when viewport shrinks
            window.addEventListener('resize', () => this.constrainAllToViewport(), { signal });

            // Track last click position for tooltip positioning
            document.addEventListener('click', (e) => { this._lastClickPos = { x: e.clientX, y: e.clientY }; }, { signal, capture: true });

            // Global mouse events for drag and resize
            document.addEventListener('mousemove', (e) => this.handleMouseMove(e), { signal });
            document.addEventListener('mouseup', () => this.handleMouseUp(), { signal });

            // Global touch events for drag and resize (mobile/tablet support)
            document.addEventListener('touchmove', (e) => this.handleTouchMove(e), { signal, passive: false });
            document.addEventListener('touchend', () => this.handleMouseUp(), { signal });

            // Mark as initialized
            this._initialized = true;
        },

        openStream(channelData) {
            // Check if we already have a player for this channel
            const existingPlayer = this.players.find(p => p.url === channelData.url);
            if (existingPlayer) {
                this.bringToFront(existingPlayer.id);
                return;
            }

            // Enforce max concurrent player limit (0 = unlimited)
            if (this.maxPlayers > 0 && this.players.length >= this.maxPlayers) {
                this.showLimitMessage();
                return;
            }

            const playerId = 'floating-player-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9);

            const player = {
                id: playerId,
                channelId: channelData.id ?? null,
                channelType: channelData.type || 'channel', // default to 'channel' if type not provided
                title: channelData.title || channelData.name || 'Unknown Channel',
                display_title: channelData.display_title || channelData.title || channelData.name || 'Unknown Channel',
                logo: channelData.logo || channelData.icon || '',
                url: channelData.url || '',
                format: channelData.format || 'ts',
                content_type: channelData.content_type || '',
                stream_id: channelData.stream_id ?? null,
                playlist_id: channelData.playlist_id ?? null,
                series_id: channelData.series_id ?? null,
                season_number: channelData.season_number ?? null,
                zIndex: ++this.zIndexCounter,
                position: this.getRandomPosition(),
                size: { width: 480, height: 270 }, // 16:9 aspect ratio
                isMinimized: false,
                isPiP: false
            };

            this.players.push(player);
        },

        getRandomPosition() {
            const maxX = document.documentElement.clientWidth - 500; // Account for player width
            const maxY = document.documentElement.clientHeight - 300; // Account for player height
            const padding = 50;

            return {
                x: Math.max(padding, Math.random() * maxX),
                y: Math.max(padding, Math.random() * maxY)
            };
        },

        closeStream(playerId, { notifyServer = true } = {}) {
            const playerIndex = this.players.findIndex(p => p.id === playerId);
            if (playerIndex !== -1) {
                const player = this.players[playerIndex];

                // Exit PiP if this player is in PiP mode
                const videoElement = document.getElementById(player.id + '-video');
                if (document.pictureInPictureElement === videoElement) {
                    document.exitPictureInPicture().catch(() => { });
                }

                // Notify server to stop the proxy stream (skip for transfers to pop-out)
                if (notifyServer) {
                    this.notifyServerStreamStop(player);
                }
                if (videoElement?._streamPlayer) {
                    videoElement._streamPlayer.cleanup();
                }

                // Remove from array
                this.players.splice(playerIndex, 1);
            }
        },

        cleanupAllStreams() {
            if (this._finalized) return;
            this._finalized = true;

            this.players.forEach(player => {
                // Notify server to stop the proxy stream (best-effort via sendBeacon)
                this.notifyServerStreamStop(player);
                // Cleanup local media via video element
                const videoElement = document.getElementById(player.id + '-video');
                if (videoElement?._streamPlayer) {
                    try {
                        videoElement._streamPlayer.cleanup();
                    } catch (e) {
                        console.warn('Error cleaning up stream:', e);
                    }
                }
            });
            this.players = [];

            // Remove all event listeners registered by this instance
            this._abortController?.abort();
            this._abortController = null;

            // Reset initialization flag
            this._initialized = false;
        },

        pauseAllStreams() {
            this.players.forEach(player => {
                if (player.content_type === 'live') return;
                const videoElement = document.getElementById(player.id + '-video');
                if (videoElement && !videoElement.paused) {
                    videoElement.pause();
                    player._wasPausedByVisibility = true;
                }
            });
        },

        resumeAllStreams() {
            this.players.forEach(player => {
                if (player._wasPausedByVisibility) {
                    const videoElement = document.getElementById(player.id + '-video');
                    if (videoElement) {
                        videoElement.play().catch(() => {
                            // Autoplay may be blocked; user can manually resume
                        });
                    }
                    player._wasPausedByVisibility = false;
                }
            });
        },

        /**
         * Notify the server to stop the proxy stream for this player.
         * Delegates to the shared notifyProxyStreamStop utility in stream-viewer.js.
         */
        notifyServerStreamStop(player) {
            if (window.notifyProxyStreamStop) {
                window.notifyProxyStreamStop(player.channelId, player.channelType, player.id);
            }
        },

        showLimitMessage() {
            const existing = document.getElementById('floating-player-limit-msg');
            if (existing) existing.remove();

            const msg = document.createElement('div');
            msg.id = 'floating-player-limit-msg';
            msg.textContent = `Player limit reached (${this.maxPlayers}). Close one to open another.`;
            Object.assign(msg.style, {
                position: 'fixed',
                background: 'rgba(0, 0, 0, 0.9)',
                color: '#fff',
                padding: '6px 12px',
                borderRadius: '6px',
                fontSize: '12px',
                zIndex: '99999',
                pointerEvents: 'none',
                whiteSpace: 'nowrap',
                transition: 'opacity 0.3s',
            });
            document.body.appendChild(msg);

            // Position above the last click location
            const pos = this._lastClickPos;
            const msgRect = msg.getBoundingClientRect();
            const vw = document.documentElement.clientWidth;
            msg.style.left = Math.max(8, Math.min(pos.x - msgRect.width / 2, vw - msgRect.width - 8)) + 'px';
            msg.style.top = Math.max(8, pos.y - msgRect.height - 12) + 'px';

            setTimeout(() => { msg.style.opacity = '0'; }, 2000);
            setTimeout(() => { msg.remove(); }, 2300);
        },

        bringToFront(playerId) {
            const player = this.players.find(p => p.id === playerId);
            if (player) {
                player.zIndex = ++this.zIndexCounter;
            }
        },

        toggleMinimize(playerId) {
            const player = this.players.find(p => p.id === playerId);
            if (player) {
                player.isMinimized = !player.isMinimized;
            }
        },

        togglePiP(playerId) {
            const videoElement = document.getElementById(playerId + '-video');
            if (!videoElement) return;

            const player = this.players.find(p => p.id === playerId);

            if (document.pictureInPictureElement === videoElement) {
                document.exitPictureInPicture().catch(() => { });
            } else if (document.pictureInPictureEnabled) {
                videoElement.requestPictureInPicture().then(() => {
                    if (player) player.isPiP = true;
                }).catch(() => { });

                // Listen for PiP exit (user closes PiP window or we call exitPiP)
                videoElement.addEventListener('leavepictureinpicture', () => {
                    if (player) player.isPiP = false;
                }, { once: true });
            }
        },

        openInNewTab(player, popoutRoute) {
            if (!player || !player.url || !popoutRoute) {
                return;
            }

            // Close the floating player locally without notifying the proxy —
            // the pop-out window inherits the same client_id so the proxy sees
            // an uninterrupted connection with no gap at 0 clients.
            this.closeStream(player.id, { notifyServer: false });

            const params = new URLSearchParams({
                title: player.title ?? '',
                display_title: player.display_title ?? player.title ?? '',
                logo: player.logo ?? '',
                url: player.url ?? '',
                format: player.format ?? 'ts',
                content_type: player.content_type ?? '',
                stream_id: player.stream_id ?? '',
                playlist_id: player.playlist_id ?? '',
                series_id: player.series_id ?? '',
                season_number: player.season_number ?? '',
                client_id: player.id,
            });

            window.open(popoutRoute + '?' + params.toString(), '_blank', 'noopener');
        },

        startDrag(playerId, event) {
            event.preventDefault();
            this.bringToFront(playerId);

            const player = this.players.find(p => p.id === playerId);
            if (!player) return;

            const point = event.touches?.[0] ?? event;
            this.dragState = {
                isDragging: true,
                playerId: playerId,
                startX: point.clientX,
                startY: point.clientY,
                startLeft: player.position.x,
                startTop: player.position.y
            };
        },

        startResize(playerId, event) {
            event.preventDefault();
            event.stopPropagation();
            this.bringToFront(playerId);

            const player = this.players.find(p => p.id === playerId);
            if (!player) return;

            const point = event.touches?.[0] ?? event;
            this.resizeState = {
                isResizing: true,
                playerId: playerId,
                startX: point.clientX,
                startY: point.clientY,
                startWidth: player.size.width,
                startHeight: player.size.height
            };
        },

        handleTouchMove(event) {
            if (!this.dragState.isDragging && !this.resizeState.isResizing) return;
            event.preventDefault();
            this.handleMouseMove(event.touches[0]);
        },

        constrainAllToViewport() {
            const vw = document.documentElement.clientWidth;
            const vh = document.documentElement.clientHeight;

            this.players.forEach(player => {
                // Shrink player if it's wider/taller than viewport
                const maxWidth = Math.max(320, vw - 20);
                const aspectRatio = 16 / 9;
                if (player.size.width > maxWidth) {
                    player.size.width = maxWidth;
                    player.size.height = maxWidth / aspectRatio;
                }

                // Clamp position so at least the title bar stays visible
                player.position.x = Math.max(0, Math.min(vw - player.size.width, player.position.x));
                player.position.y = Math.max(0, Math.min(vh - 50, player.position.y));
            });
        },

        handleMouseMove(event) {
            if (this.dragState.isDragging) {
                const player = this.players.find(p => p.id === this.dragState.playerId);
                if (player) {
                    const deltaX = event.clientX - this.dragState.startX;
                    const deltaY = event.clientY - this.dragState.startY;

                    player.position.x = Math.max(0, Math.min(
                        document.documentElement.clientWidth - player.size.width,
                        this.dragState.startLeft + deltaX
                    ));
                    player.position.y = Math.max(0, Math.min(
                        document.documentElement.clientHeight - 50, // Keep title bar visible
                        this.dragState.startTop + deltaY
                    ));
                }
            }

            if (this.resizeState.isResizing) {
                const player = this.players.find(p => p.id === this.resizeState.playerId);
                if (player) {
                    const deltaX = event.clientX - this.resizeState.startX;
                    const deltaY = event.clientY - this.resizeState.startY;

                    const maxWidth = document.documentElement.clientWidth - player.position.x;
                    const maxHeight = document.documentElement.clientHeight - player.position.y - 40; // 40 for title bar

                    const newWidth = Math.min(Math.max(320, this.resizeState.startWidth + deltaX), maxWidth);
                    const newHeight = Math.min(Math.max(180, this.resizeState.startHeight + deltaY), maxHeight);

                    // Maintain 16:9 aspect ratio
                    const aspectRatio = 16 / 9;
                    if (Math.abs(deltaX) > Math.abs(deltaY)) {
                        player.size.width = newWidth;
                        player.size.height = Math.min(newWidth / aspectRatio, maxHeight);
                    } else {
                        player.size.height = newHeight;
                        player.size.width = Math.min(newHeight * aspectRatio, maxWidth);
                    }
                }
            }
        },

        handleMouseUp() {
            this.dragState.isDragging = false;
            this.resizeState.isResizing = false;
        },

        getPlayerStyle(player) {
            return {
                position: 'fixed',
                left: player.position.x + 'px',
                top: player.position.y + 'px',
                width: player.isPiP ? '280px' : player.size.width + 'px',
                height: player.isPiP ? 'auto' : (player.size.height + 40) + 'px', // Add height for title bar
                zIndex: player.zIndex
            };
        },

        getVideoStyle(player) {
            return {
                width: '100%',
                height: player.size.height + 'px'
            };
        }
    };
}

// Make multiStreamManager function globally accessible
window.multiStreamManager = multiStreamManager;
