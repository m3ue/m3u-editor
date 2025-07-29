// EPG Viewer Alpine.js Component
function epgViewer(config) {
    return {
        apiUrl: config.apiUrl,
        loading: false,
        loadingMore: false,
        error: null,
        epgData: null,
        currentDate: new Date().toISOString().split('T')[0],
        timeSlots: [],
        selectedProgramme: null,
        currentTimePosition: -1,

        // Pagination
        currentPage: 1,
        perPage: 50,
        hasMore: true,
        allChannels: {},
        allProgrammes: {},

        // Cleanup resources
        timeUpdateInterval: null,
        scrollEventListener: null,

        init() {
            this.generateTimeSlots();
            this.updateCurrentTime();
            // Update current time every minute
            this.timeUpdateInterval = setInterval(() => this.updateCurrentTime(), 60000);

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
            console.log('Current time:', now);
            console.log('Current date:', this.currentDate);
            console.log('Is today?', this.isToday());

            if (this.isToday()) {
                const hours = now.getHours() + now.getMinutes() / 60;
                this.currentTimePosition = hours * 100; // 100px per hour
                console.log('Current time position:', this.currentTimePosition, 'px (', hours, 'hours )');
            } else {
                this.currentTimePosition = -1;
            }
        },

        isToday() {
            const today = new Date().toISOString().split('T')[0];
            return this.currentDate === today;
        },

        async loadEpgData() {
            this.loading = true;
            this.error = null;
            this.currentPage = 1;
            this.allChannels = {};
            this.allProgrammes = {};

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
                const url = `${this.apiUrl}?start_date=${this.currentDate}&end_date=${this.getEndDate()}&page=${page}&per_page=${this.perPage}`;
                console.log('Request URL:', url);

                const response = await fetch(url);

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();
                console.log('EPG data loaded successfully:', data);

                // Merge channels and programmes
                Object.assign(this.allChannels, data.channels || {});
                Object.assign(this.allProgrammes, data.programmes || {});

                // Update pagination state
                this.currentPage = data.pagination.current_page;
                this.hasMore = data.pagination.has_more;

                // Set epgData for template compatibility
                this.epgData = {
                    epg: data.epg || null,
                    playlist: data.playlist || null,
                    date_range: data.date_range,
                    channels: this.allChannels,
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

        selectProgramme(programme) {
            this.selectedProgramme = programme;
        },

        previousDay() {
            const date = new Date(this.currentDate);
            date.setDate(date.getDate() - 1);
            this.currentDate = date.toISOString().split('T')[0];
            this.loadEpgData();
        },

        nextDay() {
            const date = new Date(this.currentDate);
            date.setDate(date.getDate() + 1);
            this.currentDate = date.toISOString().split('T')[0];
            this.loadEpgData();
        },

        goToToday() {
            this.currentDate = new Date().toISOString().split('T')[0];
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

                console.log('Scrolled to current time position:', scrollLeft);
            }
        },

        formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        },

        formatTime(hour) {
            console.log('Formatting time for hour:', hour);
            // Format as HH:00 
            const formatted = hour.toString().padStart(2, '0') + ':00';
            console.log('Formatted time:', formatted);
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
            const programmes = this.allProgrammes?.[channelId] || [];
            if (programmes.length === 0) {
                console.log('No programmes found for channel:', channelId);
            }
            return programmes;
        },

        getProgrammeStyle(programme) {
            const start = new Date(programme.start);
            const stop = programme.stop ? new Date(programme.stop) : new Date(start.getTime() + 30 * 60 * 1000);

            const dayStart = new Date(start);
            dayStart.setHours(0, 0, 0, 0);

            const startHours = (start - dayStart) / (1000 * 60 * 60);
            const durationHours = (stop - start) / (1000 * 60 * 60);

            const leftPos = startHours * 100; // 100px per hour
            const width = Math.max(durationHours * 100, 60); // Minimum 60px width

            return `left: ${leftPos}px; width: ${width}px;`;
        },

        getProgrammeColorClass(programme) {
            const now = new Date();
            const start = new Date(programme.start);
            const stop = programme.stop ? new Date(programme.stop) : new Date(start.getTime() + 30 * 60 * 1000);

            if (this.isToday() && start <= now && stop >= now) {
                return 'bg-rose-200 dark:bg-rose-800 border border-rose-400 dark:border-rose-600 hover:bg-rose-300 dark:hover:bg-rose-700'; // Currently playing
            } else if (start > now || !this.isToday()) {
                return 'bg-indigo-100 dark:bg-indigo-900 border border-indigo-300 dark:border-indigo-700 hover:bg-indigo-200 dark:hover:bg-indigo-800'; // Future programme
            } else {
                return 'bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 hover:bg-gray-200 dark:hover:bg-gray-600'; // Past programme
            }
        }
    }
}

// Make epgViewer function globally accessible
window.epgViewer = epgViewer;
