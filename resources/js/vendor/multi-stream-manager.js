// Multi-Stream Manager Alpine.js Component
function multiStreamManager() {
    return {
        players: [],
        zIndexCounter: 1000,
        _initialized: false,
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

            // Check if we already have a listener
            if (window._floatingStreamListenerAdded) {
                return;
            }

            // Listen for new stream requests
            window.addEventListener('openFloatingStream', (event) => {
                let detail = event.detail;
                if (Array.isArray(detail)) {
                    detail = detail[0];
                }
                console.log('Received openFloatingStream event:', detail);
                event.stopPropagation(); // Prevent event bubbling
                this.openStream(detail);
            });

            // Mark that we've added the listener
            window._floatingStreamListenerAdded = true;

            // Cleanup on page unload
            window.addEventListener('beforeunload', () => {
                this.cleanupAllStreams();
            });

            // Global mouse events for drag and resize
            document.addEventListener('mousemove', (e) => this.handleMouseMove(e));
            document.addEventListener('mouseup', () => this.handleMouseUp());

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

            const playerId = 'floating-player-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);

            const player = {
                id: playerId,
                title: channelData.title || channelData.name || 'Unknown Channel',
                logo: channelData.logo || channelData.icon || '',
                url: channelData.url || '',
                format: channelData.format || 'ts',
                zIndex: ++this.zIndexCounter,
                position: this.getRandomPosition(),
                size: { width: 480, height: 270 }, // 16:9 aspect ratio
                isMinimized: false,
                streamPlayer: null
            };

            this.players.push(player);
        },

        getRandomPosition() {
            const maxX = window.innerWidth - 500; // Account for player width
            const maxY = window.innerHeight - 300; // Account for player height
            const padding = 50;

            return {
                x: Math.max(padding, Math.random() * maxX),
                y: Math.max(padding, Math.random() * maxY)
            };
        },

        initializePlayer(player) {
            const videoElement = document.getElementById(player.id + '-video');
            if (videoElement && window.streamPlayer) {
                player.streamPlayer = window.streamPlayer();
                player.streamPlayer.initPlayer(player.url, player.format, player.id + '-video');
            }
        },

        closeStream(playerId) {
            const playerIndex = this.players.findIndex(p => p.id === playerId);
            if (playerIndex !== -1) {
                const player = this.players[playerIndex];

                // Cleanup stream player via video element
                const videoElement = document.getElementById(player.id + '-video');
                if (videoElement && videoElement._streamPlayer) {
                    videoElement._streamPlayer.cleanup();
                }

                // Also cleanup via stored reference
                if (player.streamPlayer && typeof player.streamPlayer.cleanup === 'function') {
                    player.streamPlayer.cleanup();
                }

                // Remove from array
                this.players.splice(playerIndex, 1);
            }
        },

        cleanupAllStreams() {
            this.players.forEach(player => {
                // Cleanup via video element
                const videoElement = document.getElementById(player.id + '-video');
                if (videoElement && videoElement._streamPlayer) {
                    try {
                        videoElement._streamPlayer.cleanup();
                    } catch (e) {
                        console.warn('Error cleaning up video element:', e);
                    }
                }

                // Also cleanup via stored reference
                if (player.streamPlayer && typeof player.streamPlayer.cleanup === 'function') {
                    try {
                        player.streamPlayer.cleanup();
                    } catch (e) {
                        console.warn('Error cleaning up player reference:', e);
                    }
                }
            });
            this.players = [];

            // Reset initialization flag
            this._initialized = false;
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

        openInNewTab(player, popoutRoute) {
            if (!player || !player.url || !popoutRoute) {
                return;
            }

            // Close the current floating player
            this.closeStream(player.id);

            const params = new URLSearchParams({
                title: player.title ?? '',
                logo: player.logo ?? '',
                url: player.url ?? '',
                format: player.format ?? 'ts',
            });

            window.open(popoutRoute + '?' + params.toString(), '_blank', 'noopener');
        },

        // Drag functionality
        startDrag(playerId, event) {
            event.preventDefault();
            this.bringToFront(playerId);

            const player = this.players.find(p => p.id === playerId);
            if (!player) return;

            this.dragState = {
                isDragging: true,
                playerId: playerId,
                startX: event.clientX,
                startY: event.clientY,
                startLeft: player.position.x,
                startTop: player.position.y
            };
        },

        // Resize functionality
        startResize(playerId, event) {
            event.preventDefault();
            event.stopPropagation();
            this.bringToFront(playerId);

            const player = this.players.find(p => p.id === playerId);
            if (!player) return;

            this.resizeState = {
                isResizing: true,
                playerId: playerId,
                startX: event.clientX,
                startY: event.clientY,
                startWidth: player.size.width,
                startHeight: player.size.height
            };
        },

        handleMouseMove(event) {
            if (this.dragState.isDragging) {
                const player = this.players.find(p => p.id === this.dragState.playerId);
                if (player) {
                    const deltaX = event.clientX - this.dragState.startX;
                    const deltaY = event.clientY - this.dragState.startY;

                    player.position.x = Math.max(0, Math.min(
                        window.innerWidth - player.size.width,
                        this.dragState.startLeft + deltaX
                    ));
                    player.position.y = Math.max(0, Math.min(
                        window.innerHeight - 50, // Keep title bar visible
                        this.dragState.startTop + deltaY
                    ));
                }
            }

            if (this.resizeState.isResizing) {
                const player = this.players.find(p => p.id === this.resizeState.playerId);
                if (player) {
                    const deltaX = event.clientX - this.resizeState.startX;
                    const deltaY = event.clientY - this.resizeState.startY;

                    const newWidth = Math.max(320, this.resizeState.startWidth + deltaX);
                    const newHeight = Math.max(180, this.resizeState.startHeight + deltaY);

                    // Maintain 16:9 aspect ratio
                    const aspectRatio = 16 / 9;
                    if (Math.abs(deltaX) > Math.abs(deltaY)) {
                        player.size.width = newWidth;
                        player.size.height = newWidth / aspectRatio;
                    } else {
                        player.size.height = newHeight;
                        player.size.width = newHeight * aspectRatio;
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
                width: player.size.width + 'px',
                height: (player.size.height + 40) + 'px', // Add height for title bar
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
