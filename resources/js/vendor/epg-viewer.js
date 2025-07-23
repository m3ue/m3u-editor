// EPG Viewer Alpine.js Component
function epgViewer(config) {
    return {
        epgUuid: config.epgUuid,
        apiUrl: config.apiUrl,
        loading: false,
        error: null,
        epgData: null,
        currentDate: new Date().toISOString().split('T')[0],
        timeSlots: [],
        selectedProgramme: null,
        currentTimePosition: -1,

        init() {
            this.generateTimeSlots();
            this.updateCurrentTime();
            // Update current time every minute
            setInterval(() => this.updateCurrentTime(), 60000);
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
            this.selectedProgramme = null;

            try {
                console.log('Loading EPG data from:', this.apiUrl + `?start_date=${this.currentDate}&end_date=${this.currentDate}`);
                const response = await fetch(this.apiUrl + `?start_date=${this.currentDate}&end_date=${this.currentDate}`);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                this.epgData = await response.json();
                console.log('EPG data loaded:', this.epgData);
                console.log('Channels found:', Object.keys(this.epgData?.channels || {}).length);
                console.log('Programmes found:', Object.keys(this.epgData?.programmes || {}).length);
                
                // Debug first few programmes
                const firstChannelId = Object.keys(this.epgData?.channels || {})[0];
                if (firstChannelId) {
                    console.log('First channel programmes:', this.epgData?.programmes?.[firstChannelId]?.slice(0, 3));
                }
                
                this.updateCurrentTime();
                
                // Auto-scroll to current time if viewing today
                if (this.isToday()) {
                    setTimeout(() => this.scrollToCurrentTime(), 100);
                }
            } catch (error) {
                console.error('Error loading EPG data:', error);
                this.error = 'Failed to load EPG data. Please try again.';
            } finally {
                this.loading = false;
            }
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
            const programmes = this.epgData?.programmes?.[channelId] || [];
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
                return 'bg-green-200 border border-green-400 hover:bg-green-300'; // Currently playing
            } else if (start > now || !this.isToday()) {
                return 'bg-blue-100 border border-blue-300 hover:bg-blue-200'; // Future programme
            } else {
                return 'bg-gray-100 border border-gray-300 hover:bg-gray-200'; // Past programme
            }
        }
    }
}

// Make epgViewer function globally accessible
window.epgViewer = epgViewer;
