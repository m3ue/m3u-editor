// EPG Viewer Alpine.js Component
function epgViewer(config) {
    return {
        apiUrl: config.apiUrl,
        loading: false,
        loadingMore: false,
        error: null,
        epgData: null,
        currentDate: (() => {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const dateStr = `${year}-${month}-${day}`;
            return dateStr;
        })(),
        timeSlots: [],
        currentTimePosition: -1,

        // Mobile detection
        isMobile: window.innerWidth < 768,

        // Pagination
        currentPage: 1,
        perPage: 50,
        hasMore: true,
        allChannels: {},
        allProgrammes: {},

        // Pre-built channels with programmes for efficient template access
        processedChannels: {},

        // Search functionality
        searchTerm: '',
        isSearchActive: false,

        // Modal loading state to prevent rapid clicking
        modalLoading: false,

        // Channel editing modal (handled outside Livewire to prevent re-renders)
        channelEditModal: null,

        // Cleanup resources
        timeUpdateInterval: null,
        scrollEventListener: null,

        init() {
            this.generateTimeSlots();
            this.updateCurrentTime();
            // Update current time every minute
            this.timeUpdateInterval = setInterval(() => this.updateCurrentTime(), 60000);

            // Setup mobile detection with resize listener
            const updateMobile = () => { this.isMobile = window.innerWidth < 768; };
            window.addEventListener('resize', updateMobile);
            this.resizeListener = updateMobile;

            // Setup scroll listener for pagination
            this.$nextTick(() => {
                // The main scrollable container is the timeline-scroll element
                const timelineContainer = document.querySelector('.timeline-scroll');
                if (timelineContainer) {
                    this.scrollEventListener = this.handleScroll.bind(this);
                    timelineContainer.addEventListener('scroll', this.scrollEventListener);
                }
            });

            // Scroll to current time on load
            this.scrollToCurrentTime();
        },

        destroy() {
            // Clear the time update interval
            if (this.timeUpdateInterval) {
                clearInterval(this.timeUpdateInterval);
                this.timeUpdateInterval = null;
            }

            // Remove resize listener
            if (this.resizeListener) {
                window.removeEventListener('resize', this.resizeListener);
                this.resizeListener = null;
            }

            // Remove scroll event listener
            if (this.scrollEventListener) {
                const timelineContainer = document.querySelector('.timeline-scroll');
                if (timelineContainer) {
                    timelineContainer.removeEventListener('scroll', this.scrollEventListener);
                }
                this.scrollEventListener = null;
            }

            console.log('EPG Viewer component destroyed and cleaned up');
        },

        generateTimeSlots() {
            this.timeSlots = [];
            for (let hour = 0; hour < 24; hour++) {
                this.timeSlots.push(hour);
            }
        },

        updateCurrentTime() {
            const now = new Date();

            if (this.isToday()) {
                const hours = now.getHours() + now.getMinutes() / 60;
                this.currentTimePosition = hours * 100; // 100px per hour
            } else {
                this.currentTimePosition = -1;
            }
        },

        isToday() {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const today = `${year}-${month}-${day}`;
            return this.currentDate === today;
        },

        async loadEpgData() {
            this.loading = true;
            this.error = null;
            this.currentPage = 1;
            this.allChannels = {};
            this.allProgrammes = {};
            this.processedChannels = {};

            try {
                await this.loadPage(1);
                this.loading = false;
            } catch (error) {
                console.error('Error loading EPG data:', error);
                this.error = 'Failed to load EPG data: ' + error.message;
                this.loading = false;
            }
        },

        async loadPage(page = 1) {
            const isInitialLoad = page === 1;

            if (!isInitialLoad) {
                this.loadingMore = true;
            }

            try {
                console.log(`Loading page ${page} of EPG data...`);
                let url = `${this.apiUrl}?start_date=${this.currentDate}&end_date=${this.getEndDate()}&page=${page}&per_page=${this.perPage}`;

                // Add search parameter if active
                if (this.isSearchActive && this.searchTerm.trim()) {
                    url += `&search=${encodeURIComponent(this.searchTerm.trim())}`;
                }

                console.log('Request URL:', url);

                const response = await fetch(url);

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.suggestion || `HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();
                console.log('EPG data loaded successfully:', data);

                // Process only new channels incrementally
                this.processNewChannels(data.channels || {}, data.programmes || {});

                // Update pagination state
                this.currentPage = data.pagination.current_page;
                this.hasMore = data.pagination.has_more;

                // Set epgData for template compatibility
                this.epgData = {
                    epg: data.epg || null,
                    playlist: data.playlist || null,
                    date_range: data.date_range,
                    channels: this.processedChannels,
                    programmes: this.allProgrammes,
                    pagination: data.pagination
                };

                console.log('Loaded channels:', Object.keys(this.allChannels).length);
                console.log('Has more pages:', this.hasMore);

            } catch (error) {
                console.error('Error loading page:', error);
                if (isInitialLoad) {
                    throw error;
                }
            } finally {
                if (!isInitialLoad) {
                    this.loadingMore = false;
                }
            }
        },

        async loadMoreData() {
            if (!this.hasMore || this.loadingMore) {
                return;
            }

            const nextPage = this.currentPage + 1;
            await this.loadPage(nextPage);
        },

        handleScroll(event) {
            const container = event.target;
            const scrollTop = container.scrollTop;
            const scrollHeight = container.scrollHeight;
            const clientHeight = container.clientHeight;

            // For pagination, we only care about vertical scrolling
            // Check if we're near the bottom (within 200px)
            const nearBottom = scrollTop + clientHeight >= scrollHeight - 200;

            if (nearBottom && this.hasMore && !this.loadingMore) {
                console.log('Near bottom, loading more data...');
                this.loadMoreData();
            }
        },

        getEndDate() {
            return this.currentDate;
        },

        previousDay() {
            const [year, month, day] = this.currentDate.split('-').map(Number);
            const date = new Date(year, month - 1, day); // month is 0-indexed in Date constructor
            date.setDate(date.getDate() - 1);

            const newYear = date.getFullYear();
            const newMonth = String(date.getMonth() + 1).padStart(2, '0');
            const newDay = String(date.getDate()).padStart(2, '0');
            this.currentDate = `${newYear}-${newMonth}-${newDay}`;

            // Clear search when navigating dates
            this.searchTerm = '';
            this.isSearchActive = false;

            this.loadEpgData();
        },

        nextDay() {
            const [year, month, day] = this.currentDate.split('-').map(Number);
            const date = new Date(year, month - 1, day); // month is 0-indexed in Date constructor
            date.setDate(date.getDate() + 1);

            const newYear = date.getFullYear();
            const newMonth = String(date.getMonth() + 1).padStart(2, '0');
            const newDay = String(date.getDate()).padStart(2, '0');
            this.currentDate = `${newYear}-${newMonth}-${newDay}`;

            // Clear search when navigating dates
            this.searchTerm = '';
            this.isSearchActive = false;

            this.loadEpgData();
        },

        goToToday() {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            this.currentDate = `${year}-${month}-${day}`;

            // Clear search when navigating dates
            this.searchTerm = '';
            this.isSearchActive = false;

            this.loadEpgData();
        },

        scrollToCurrentTime() {
            if (this.isToday() && this.currentTimePosition >= 0) {
                // Scroll to current time position minus some padding to center it
                const scrollLeft = Math.max(0, this.currentTimePosition - 300); // 300px padding
                const timelineElement = document.querySelector('.timeline-scroll');
                const timeHeaderElement = document.querySelector('.time-header-scroll');

                if (timelineElement) {
                    timelineElement.scrollLeft = scrollLeft;
                }
                if (timeHeaderElement) {
                    timeHeaderElement.scrollLeft = scrollLeft;
                }
            }
        },

        formatDate(dateStr) {
            // Parse the date string safely in local timezone
            const [year, month, day] = dateStr.split('-').map(Number);
            const date = new Date(year, month - 1, day); // month is 0-indexed

            return date.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        },

        formatTime(hour) {
            // Format as HH:00 
            const formatted = hour.toString().padStart(2, '0') + ':00';
            return formatted;
        },

        formatProgrammeTime(programme) {
            const start = new Date(programme.start);
            const stop = programme.stop ? new Date(programme.stop) : null;

            const startTime = start.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });

            if (stop) {
                const stopTime = stop.toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                });
                return `${startTime} - ${stopTime}`;
            }

            return startTime;
        },

        getChannelProgrammes(channelId) {
            return this.allProgrammes?.[channelId] || [];
        },

        // Process new channels incrementally without rebuilding everything
        processNewChannels(newChannels, newProgrammes) {
            // Merge new data efficiently
            Object.assign(this.allChannels, newChannels);
            Object.assign(this.allProgrammes, newProgrammes);

            // Process the new channels synchronously to maintain DOM alignment
            for (const [channelId, channelData] of Object.entries(newChannels)) {
                this.processedChannels[channelId] = {
                    ...channelData,
                    programmes: this.allProgrammes[channelId] || []
                };
            }
        },

        getTooltipContent(programme) {
            let content = '<p><strong>' + programme.title + '</strong></p>';
            content += '<small>' + this.formatProgrammeTime(programme) + '</small><br/>';
            if (programme.new) {
                content += '<small>New Episode</small><br/>';
            }
            if (programme.episode_num) {
                // Assuming episode_num is in xmltv_ns format
                let season = 0, episode = 0;
                const parts = programme.episode_num.split('.');
                if (parts.length > 0) {
                    season = parseInt(parts[0], 10) + 1; // xmltv_ns is zero-based
                }
                if (parts.length > 1) {
                    episode = parseInt(parts[1], 10) + 1;
                }
                if (season > 0 && episode > 0) {
                    content += '<small>Season ' + season + ', Episode ' + episode + '</small><br/>';
                } else if (season > 0) {
                    content += '<small>Season ' + season + '</small><br/>';
                } else if (episode > 0) {
                    content += '<small>Episode ' + episode + '</small><br/>';
                }
            }
            if (programme.desc && programme.desc.trim()) {
                content += '<p>' + programme.desc + '</p>';
            }
            if (programme.category && programme.category.trim()) {
                content += '<small>Category: ' + programme.category + '</small>';
            }
            return content;
        },

        getProgrammeSeasonEpisode(programme) {
            let content = '';
            if (programme.episode_num) {
                // Assuming episode_num is in xmltv_ns format
                let season = 0, episode = 0;
                const parts = programme.episode_num.split('.');
                if (parts.length > 0) {
                    season = parseInt(parts[0], 10) + 1; // xmltv_ns is zero-based
                }
                if (parts.length > 1) {
                    episode = parseInt(parts[1], 10) + 1;
                }
                if (season > 0 && episode > 0) {
                    content += 'Season ' + season + ', Episode ' + episode;
                } else if (season > 0) {
                    content += 'Season ' + season;
                } else if (episode > 0) {
                    content += 'Episode ' + episode;
                }
            }
            return content;
        },

        getProgrammeStyle(programme) {
            const start = new Date(programme.start);
            const stop = programme.stop ? new Date(programme.stop) : new Date(start.getTime() + 30 * 60 * 1000);

            const dayStart = new Date(start);
            dayStart.setHours(0, 0, 0, 0);

            const startHours = (start - dayStart) / (1000 * 60 * 60);
            const durationHours = (stop - start) / (1000 * 60 * 60);

            // 100px per hour
            const pixelsPerHour = 100;
            const minWidth = 60;

            const leftPos = startHours * pixelsPerHour;
            const width = Math.max(durationHours * pixelsPerHour, minWidth);

            return `left: ${leftPos}px; width: ${width}px;`;
        },

        getProgrammeColorClass(programme) {
            const now = new Date();
            const start = new Date(programme.start);
            const stop = programme.stop ? new Date(programme.stop) : new Date(start.getTime() + 30 * 60 * 1000);

            if (this.isToday() && start <= now && stop >= now) {
                return 'bg-gradient-to-r from-rose-200 to-rose-500 dark:from-rose-700 dark:to-rose-900 border border-rose-400 dark:border-rose-600 hover:bg-rose-300 dark:hover:bg-rose-700'; // Currently playing
            } else if (start > now || !this.isToday()) {
                return 'bg-gradient-to-r from-indigo-100 to-indigo-300 dark:from-indigo-700 dark:to-indigo-900 border border-indigo-300 dark:border-indigo-700 hover:bg-indigo-200 dark:hover:bg-indigo-800'; // Future programme
            } else {
                return 'bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 hover:bg-gray-200 dark:hover:bg-gray-600'; // Past programme
            }
        },

        // Search functionality
        performSearch() {
            if (!this.searchTerm.trim()) {
                return;
            }

            console.log('Performing search for:', this.searchTerm);
            this.isSearchActive = true;
            this.loadEpgData(); // Reload data with search
        },

        clearSearch() {
            console.log('Clearing search');
            this.searchTerm = '';
            this.isSearchActive = false;
            this.loadEpgData(); // Reload data without search
        },

        // Handle Enter key in search input
        handleSearchKeydown(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.performSearch();
            }
        },

        // Refresh EPG data (called after channel updates)
        async refreshEpgData(data) {
            console.log('Refreshing EPG data after channel update...', data);

            // Show loading state briefly to provide visual feedback
            this.loadingMore = true;

            try {
                // For backward compatibility - if data is an array of channels, update them
                if (Array.isArray(data)) {
                    for (const updatedChannel of data) {
                        this.updateChannelData(updatedChannel);
                    }
                } else if (data) {
                    // Single channel update
                    this.updateChannelData(data);
                }
            } catch (error) {
                console.error('Error refreshing EPG data:', error);
                this.error = 'Failed to refresh EPG data: ' + error.message;
            } finally {
                // Hide loading state
                setTimeout(() => {
                    this.loadingMore = false;
                }, 300);
            }
        },

        // Update specific channel data without full reload
        updateChannelData(updatedChannelData) {
            console.log('Updating channel data:', updatedChannelData);

            // Handle both single object and array of objects
            const channels = Array.isArray(updatedChannelData) ? updatedChannelData : [updatedChannelData];

            for (const updatedChannel of channels) {
                const databaseId = updatedChannel.database_id;
                let foundChannelId = null;

                // Search through the channels object to find matching database_id
                if (this.epgData?.channels) {
                    for (const [channelId, channelData] of Object.entries(this.epgData.channels)) {
                        if (channelData.database_id === databaseId) {
                            foundChannelId = channelId;
                            break;
                        }
                    }
                }

                if (foundChannelId && this.epgData.channels[foundChannelId]) {
                    console.log(`Found channel with database_id ${databaseId} at channel_id ${foundChannelId}`);

                    // Update the channel's display data
                    this.epgData.channels[foundChannelId].display_name = updatedChannel.display_name;
                    this.epgData.channels[foundChannelId].icon = updatedChannel.icon;

                    // Update format if provided
                    if (updatedChannel.format) {
                        this.epgData.channels[foundChannelId].format = updatedChannel.format;
                    }

                    // Update URL if provided
                    if (updatedChannel.url) {
                        this.epgData.channels[foundChannelId].url = updatedChannel.url;
                    }

                    // Update programmes if provided
                    if (updatedChannel.programmes) {
                        this.epgData.channels[foundChannelId].programmes = updatedChannel.programmes;
                        // Also update the allProgrammes cache
                        this.allProgrammes[foundChannelId] = updatedChannel.programmes;
                    }

                    console.log(`Updated channel ${foundChannelId} (database_id: ${databaseId}) successfully`);
                } else {
                    console.warn(`Channel with database_id ${databaseId} not found in current EPG data`);
                    console.log('Available channels:', Object.keys(this.epgData?.channels || {}));
                    console.log('Available database_ids:', Object.values(this.epgData?.channels || {}).map(c => c.database_id));
                }
            }
        },

        // Handle channel edit modal outside of Livewire to prevent re-renders
        openChannelEditModal(detail) {
            console.log('Opening channel edit modal for channel:', detail.channelId);

            // Create a simple modal or redirect to edit page
            // For now, we'll use a browser-native approach or integrate with Filament's modal system
            const channelId = detail.channelId;
            const type = detail.type;

            // You can implement a custom modal here or redirect to an edit page
            // This prevents Livewire from re-rendering the entire EPG view
            console.log(`Edit channel ${channelId} of type ${type}`);

            // For demonstration, we'll create a simple alert
            // In a real implementation, you'd want to create a proper modal
            if (confirm(`Edit channel ${channelId}? (This is a placeholder - implement proper modal)`)) {
                // Call the Livewire method without re-rendering the component
                this.$wire.call('updateChannel', channelId, { name: 'Updated Channel' });
            }
        }
    }
}

// Make epgViewer function globally accessible
window.epgViewer = epgViewer;
