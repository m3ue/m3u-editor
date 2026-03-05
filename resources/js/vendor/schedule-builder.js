// Schedule Builder Alpine.js Component
function scheduleBuilder(config) {
    return {
        networkId: config.networkId,
        scheduleWindowDays: config.scheduleWindowDays || 7,
        recurrenceMode: config.recurrenceMode || 'per_day',
        gapSeconds: config.gapSeconds || 0,

        // Browser timezone (IANA name, e.g. "America/New_York")
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,

        // State
        loading: false,
        loadingPool: false,
        currentDate: '',
        programmes: [],
        mediaPool: [],
        mediaSearch: '',
        showAllMedia: false,
        showCopyModal: false,
        copyTargetDate: '',

        // Now-playing status
        nowPlaying: null, // { status: 'playing'|'gap'|'empty', title?, next_title?, ... }

        // Drag & drop state
        dragSource: null, // 'pool' or 'grid'
        dragData: null,
        dropTarget: null,

        // Click-to-assign state
        selectedMediaItem: null,

        // Computed date boundaries
        startDate: '',
        endDate: '',

        // Time slots (288 five-minute slots per day)
        timeSlots: [],

        // Slot config: 5-minute intervals, 28px per slot
        SLOT_MINUTES: 5,
        SLOT_HEIGHT: 28,

        init() {
            // Set initial date to today in the user's local timezone
            const now = new Date();
            this.currentDate = this.formatDateLocal(now);

            // Set date boundaries
            this.startDate = this.currentDate;
            const end = new Date(now);
            end.setDate(end.getDate() + this.scheduleWindowDays - 1);
            this.endDate = this.formatDateLocal(end);

            // Generate time slots
            this.generateTimeSlots();

            // Load initial data
            this.loadSchedule();
            this.loadMediaPool();
            this.loadNowPlaying();

            // Refresh now-playing status every 60 seconds
            setInterval(() => this.loadNowPlaying(), 60000);
        },

        // ── Date Helpers ──────────────────────────────────────────────

        /**
         * Format a Date to YYYY-MM-DD in the user's local timezone.
         */
        formatDateLocal(date) {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        },

        parseDate(str) {
            const [y, m, d] = str.split('-').map(Number);
            return new Date(y, m - 1, d);
        },

        get currentDateDisplay() {
            const date = this.parseDate(this.currentDate);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
            });
        },

        get currentDayOfWeek() {
            const date = this.parseDate(this.currentDate);
            return date.toLocaleDateString('en-US', { weekday: 'long' });
        },

        canGoPrevious() {
            return this.currentDate > this.startDate;
        },

        canGoNext() {
            return this.currentDate < this.endDate;
        },

        previousDay() {
            const date = this.parseDate(this.currentDate);
            date.setDate(date.getDate() - 1);
            this.currentDate = this.formatDateLocal(date);
            this.loadSchedule();
        },

        nextDay() {
            const date = this.parseDate(this.currentDate);
            date.setDate(date.getDate() + 1);
            this.currentDate = this.formatDateLocal(date);
            this.loadSchedule();
        },

        goToToday() {
            const now = new Date();
            this.currentDate = this.formatDateLocal(now);
            this.loadSchedule();
        },

        get availableDates() {
            const dates = [];
            const start = this.parseDate(this.startDate);
            const end = this.parseDate(this.endDate);

            for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
                const value = this.formatDateLocal(d);
                const label = d.toLocaleDateString('en-US', {
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric',
                });
                dates.push({ value, label });
            }
            return dates;
        },

        // ── Time Slots ───────────────────────────────────────────────

        generateTimeSlots() {
            this.timeSlots = [];
            for (let hour = 0; hour < 24; hour++) {
                for (let minute = 0; minute < 60; minute += this.SLOT_MINUTES) {
                    this.timeSlots.push({
                        time: String(hour).padStart(2, '0') + ':' + String(minute).padStart(2, '0'),
                        label: this.formatTimeLabel(hour, minute),
                        isHour: minute === 0,
                        hour: hour,
                        minute: minute,
                    });
                }
            }
        },

        formatTimeLabel(hour, minute) {
            const period = hour < 12 ? 'AM' : 'PM';
            const displayHour = hour === 0 ? 12 : hour > 12 ? hour - 12 : hour;
            const displayMinute = String(minute).padStart(2, '0');
            return `${displayHour}:${displayMinute} ${period}`;
        },

        // ── Slot-from-Y Position ─────────────────────────────────────
        // Find which actual rendered slot row the cursor is over by comparing
        // event.clientY against each slot row's getBoundingClientRect().
        // This is reliable regardless of padding, borders, or rendering
        // differences that make the assumed SLOT_HEIGHT inaccurate.

        getSlotTimeFromY(event) {
            const gridContainer = this.$refs.timeGrid;
            if (!gridContainer) {
                return null;
            }

            const slotRows = gridContainer.querySelectorAll('.slot-row[data-slot-time]');
            if (!slotRows.length) {
                return null;
            }

            const cursorY = event.clientY;

            // Check if cursor is above the first slot
            const firstRect = slotRows[0].getBoundingClientRect();
            if (cursorY < firstRect.top) {
                return slotRows[0].getAttribute('data-slot-time');
            }

            // Find the slot whose bounding rect contains the cursor Y
            for (const row of slotRows) {
                const rect = row.getBoundingClientRect();
                if (cursorY >= rect.top && cursorY < rect.bottom) {
                    return row.getAttribute('data-slot-time');
                }
            }

            // Cursor is below the last slot — return last slot
            return slotRows[slotRows.length - 1].getAttribute('data-slot-time');
        },

        // ── Data Loading ─────────────────────────────────────────────

        async loadSchedule() {
            this.loading = true;
            try {
                const result = await this.$wire.getScheduleForDate(this.currentDate, this.timezone);
                this.programmes = result || [];
            } catch (err) {
                console.error('Failed to load schedule:', err);
                this.programmes = [];
            } finally {
                this.loading = false;
            }
        },

        async loadMediaPool() {
            this.loadingPool = true;
            try {
                const result = await this.$wire.getMediaPool(this.showAllMedia);
                this.mediaPool = result || [];
            } catch (err) {
                console.error('Failed to load media pool:', err);
                this.mediaPool = [];
            } finally {
                this.loadingPool = false;
            }
        },

        async loadNowPlaying() {
            try {
                this.nowPlaying = await this.$wire.getNowPlaying();
            } catch (err) {
                console.error('Failed to load now-playing:', err);
                this.nowPlaying = null;
            }
        },

        // ── Filtered Media Pool ──────────────────────────────────────

        get filteredMediaPool() {
            if (!this.mediaSearch.trim()) {
                return this.mediaPool;
            }
            const term = this.mediaSearch.toLowerCase().trim();
            return this.mediaPool.filter(item =>
                item.title.toLowerCase().includes(term)
            );
        },

        // ── Programme Display ────────────────────────────────────────

        getProgrammesAtSlot(slotTime) {
            // Parse the slot time (these are local-timezone hours from the backend)
            const [slotHour, slotMinute] = slotTime.split(':').map(Number);
            const slotStartMinutes = slotHour * 60 + slotMinute;
            const slotEndMinutes = slotStartMinutes + this.SLOT_MINUTES;

            return this.programmes.filter(prog => {
                // start_hour and start_minute are returned in the user's
                // local timezone by the backend — match directly against
                // the slot time without any UTC conversion.
                const progStartMinutes = (parseInt(prog.start_hour, 10) * 60)
                    + parseInt(prog.start_minute, 10);
                // A programme belongs to the slot where it starts
                return progStartMinutes >= slotStartMinutes && progStartMinutes < slotEndMinutes;
            });
        },

        getProgrammeStyle(prog, slotTime) {
            // Calculate how many 5-minute slots this programme spans
            const durationMinutes = (prog.duration_seconds || 1800) / 60;
            const slots = Math.max(1, Math.ceil(durationMinutes / this.SLOT_MINUTES));
            const height = slots * this.SLOT_HEIGHT - 2; // subtract small padding
            return `height: ${height}px; z-index: 10; pointer-events: none;`;
        },

        getTypeColor(contentableType) {
            if (contentableType && contentableType.includes('Episode')) {
                return 'bg-blue-100 dark:bg-blue-900/40 border-blue-300 dark:border-blue-700 text-blue-900 dark:text-blue-100';
            }
            return 'bg-green-100 dark:bg-green-900/40 border-green-300 dark:border-green-700 text-green-900 dark:text-green-100';
        },

        formatDuration(seconds) {
            if (!seconds) return '0m';
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            if (hours > 0) {
                return `${hours}h ${minutes}m`;
            }
            return `${minutes}m`;
        },

        // ── Drag & Drop: Media Pool → Grid ──────────────────────────

        handleMediaDragStart(event, item) {
            this.dragSource = 'pool';
            this.dragData = item;
            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/plain', JSON.stringify({
                source: 'pool',
                contentable_type: item.contentable_type,
                contentable_id: item.contentable_id,
                duration_seconds: item.duration_seconds,
            }));
        },

        handleProgrammeDragStart(event, prog) {
            this.dragSource = 'grid';
            this.dragData = prog;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', JSON.stringify({
                source: 'grid',
                programme_id: prog.id,
            }));
        },

        handleProgrammeDragEnd(event) {
            this.dragSource = null;
            this.dragData = null;
            this.dropTarget = null;
        },

        // Grid-level drag handlers — use getBoundingClientRect to determine slot
        handleGridDragOver(event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = this.dragSource === 'pool' ? 'copy' : 'move';

            // Throttle the slot lookup to avoid excessive DOM queries
            const now = Date.now();
            if (this._lastDragOverTime && now - this._lastDragOverTime < 50) {
                return;
            }
            this._lastDragOverTime = now;

            const slotTime = this.getSlotTimeFromY(event);
            if (slotTime) {
                this.dropTarget = slotTime;
            }
        },

        handleGridDragLeave(event) {
            const gridContainer = this.$refs.timeGrid;
            if (gridContainer && !gridContainer.contains(event.relatedTarget)) {
                this.dropTarget = null;
            }
        },

        async handleGridDrop(event) {
            event.preventDefault();
            const slotTime = this.getSlotTimeFromY(event);
            this.dropTarget = null;

            if (!slotTime) {
                return;
            }

            let data;
            try {
                data = JSON.parse(event.dataTransfer.getData('text/plain'));
            } catch {
                return;
            }

            if (data.source === 'pool') {
                await this.addProgrammeAt(slotTime, data.contentable_type, data.contentable_id, data.duration_seconds);
            } else if (data.source === 'grid') {
                await this.moveProgrammeTo(data.programme_id, slotTime);
            }

            this.dragSource = null;
            this.dragData = null;
        },

        // ── Click-to-Assign ──────────────────────────────────────────

        selectMediaItem(item) {
            if (
                this.selectedMediaItem &&
                this.selectedMediaItem.contentable_id === item.contentable_id &&
                this.selectedMediaItem.contentable_type === item.contentable_type
            ) {
                // Deselect on second click
                this.selectedMediaItem = null;
            } else {
                this.selectedMediaItem = item;
            }
        },

        // Grid-level click handler — use getBoundingClientRect to determine slot
        async handleGridClick(event) {
            if (!this.selectedMediaItem) return;

            const slotTime = this.getSlotTimeFromY(event);
            if (!slotTime) return;

            await this.addProgrammeAt(
                slotTime,
                this.selectedMediaItem.contentable_type,
                this.selectedMediaItem.contentable_id,
                this.selectedMediaItem.duration_seconds
            );

            // Clear selection after placing
            this.selectedMediaItem = null;
        },

        // ── Programme actions (remove button needs pointer events) ───

        async handleRemoveClick(event, programmeId) {
            event.stopPropagation();
            await this.removeProgramme(programmeId);
        },

        // ── Programme CRUD ───────────────────────────────────────────

        async addProgrammeAt(slotTime, contentableType, contentableId, durationSeconds) {
            this.loading = true;
            try {
                const result = await this.$wire.addProgramme(
                    this.currentDate,
                    slotTime,
                    this.timezone,
                    contentableType,
                    contentableId,
                    durationSeconds || null
                );

                if (result.success) {
                    // Backend returns the full day's programmes (cascade bump may have shifted others)
                    if (result.programmes) {
                        this.programmes = result.programmes;
                    } else if (result.programme) {
                        this.programmes.push(result.programme);
                        this.sortProgrammes();
                    }
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to add programme:', err);
            } finally {
                this.loading = false;
            }
        },

        async moveProgrammeTo(programmeId, slotTime) {
            this.loading = true;
            try {
                const result = await this.$wire.updateProgramme(
                    programmeId,
                    this.currentDate,
                    slotTime,
                    this.timezone
                );

                if (result.success) {
                    // Backend returns the full day's programmes (cascade bump may have shifted others)
                    if (result.programmes) {
                        this.programmes = result.programmes;
                    } else if (result.programme) {
                        const idx = this.programmes.findIndex(p => p.id === programmeId);
                        if (idx !== -1) {
                            this.programmes[idx] = result.programme;
                        }
                        this.sortProgrammes();
                    }
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to move programme:', err);
            } finally {
                this.loading = false;
            }
        },

        async removeProgramme(programmeId) {
            try {
                const result = await this.$wire.removeProgramme(programmeId);
                if (result.success) {
                    this.programmes = this.programmes.filter(p => p.id !== programmeId);
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to remove programme:', err);
            }
        },

        async appendToEnd(item) {
            this.loading = true;
            try {
                const result = await this.$wire.appendProgramme(
                    this.currentDate,
                    this.timezone,
                    item.contentable_type,
                    item.contentable_id,
                    item.duration_seconds || null
                );

                if (result.success) {
                    if (result.programmes) {
                        this.programmes = result.programmes;
                    } else if (result.programme) {
                        this.programmes.push(result.programme);
                        this.sortProgrammes();
                    }
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to append programme:', err);
            } finally {
                this.loading = false;
            }
        },

        async insertAfterProgramme(programmeId, item) {
            this.loading = true;
            try {
                const result = await this.$wire.insertAfterProgramme(
                    programmeId,
                    this.currentDate,
                    this.timezone,
                    item.contentable_type,
                    item.contentable_id,
                    item.duration_seconds || null
                );

                if (result.success) {
                    if (result.programmes) {
                        this.programmes = result.programmes;
                    } else if (result.programme) {
                        this.programmes.push(result.programme);
                        this.sortProgrammes();
                    }
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to insert programme:', err);
            } finally {
                this.loading = false;
            }
        },

        sortProgrammes() {
            this.programmes.sort((a, b) => {
                return new Date(a.start_time) - new Date(b.start_time);
            });
        },

        // ── Day Actions ──────────────────────────────────────────────

        async clearCurrentDay() {
            if (!confirm('Clear all programmes for this day?')) return;

            this.loading = true;
            try {
                const result = await this.$wire.clearDay(this.currentDate, this.timezone);
                if (result.success) {
                    this.programmes = [];
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to clear day:', err);
            } finally {
                this.loading = false;
            }
        },

        openCopyModal() {
            // Pre-select the first available date that isn't the current day
            const firstOther = this.availableDates.find(d => d.value !== this.currentDate);
            this.copyTargetDate = firstOther ? firstOther.value : '';
            this.showCopyModal = true;
        },

        async copyDay() {
            if (!this.copyTargetDate || this.copyTargetDate === this.currentDate) return;

            const targetDate = this.copyTargetDate;
            this.loading = true;
            this.showCopyModal = false;
            try {
                const result = await this.$wire.copyDaySchedule(this.currentDate, targetDate, this.timezone);
                if (result.success) {
                    // Navigate to the target date so the user can see the copied schedule
                    this.currentDate = targetDate;
                    await this.loadSchedule();
                }
            } catch (err) {
                console.error('Failed to copy day:', err);
            } finally {
                this.loading = false;
            }
        },

        async applyWeeklyTemplate() {
            if (!confirm('Apply the current week as a template? This will overwrite programmes beyond the first 7 days.')) return;

            this.loading = true;
            try {
                await this.$wire.applyWeeklyTemplate();
            } catch (err) {
                console.error('Failed to apply weekly template:', err);
            } finally {
                this.loading = false;
            }
        },
    };
}

// Make scheduleBuilder globally accessible
window.scheduleBuilder = scheduleBuilder;
