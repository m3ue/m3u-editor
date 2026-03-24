// Schedule Builder Alpine.js Component — List-Based Approach
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
        loadingMorePool: false,
        currentDate: '',
        programmes: [],
        mediaPool: [],
        mediaPage: 1,
        mediaHasMore: false,
        mediaSearch: '',
        showAllMedia: false,
        _mediaSearchTimer: null,
        copyTargetDate: '',
        pendingRemoveId: null,
        rowDragIndex: null,
        rowDragOverIndex: null,
        mediaPoolCollapsed: false,

        // Now-playing status
        nowPlaying: null,

        // Drag from pool
        poolDragItem: null,

        // Pin editing
        editingPinId: null,
        editingPinTime: '',

        // Computed date boundaries
        startDate: '',
        endDate: '',

        init() {
            this.mediaPoolCollapsed = window.innerWidth < 1024;

            const now = new Date();
            this.currentDate = this.formatDateLocal(now);

            this.startDate = this.currentDate;
            const end = new Date(now);
            end.setDate(end.getDate() + this.scheduleWindowDays - 1);
            this.endDate = this.formatDateLocal(end);

            this.loadSchedule();
            this.loadMediaPool();
            this.loadNowPlaying();

            setInterval(() => this.loadNowPlaying(), 60000);

            window.addEventListener('resize', () => {
                if (window.innerWidth >= 1024) {
                    this.mediaPoolCollapsed = false;
                }
            });

            // When showAll is enabled, re-fetch server-side as search term changes.
            // (Client-side filtering handles the non-showAll case.)
            this.$watch('mediaSearch', () => {
                if (!this.showAllMedia) { return; }
                clearTimeout(this._mediaSearchTimer);
                this._mediaSearchTimer = setTimeout(() => this.loadMediaPool(), 300);
            });
        },

        toggleMediaPool() {
            this.mediaPoolCollapsed = !this.mediaPoolCollapsed;
        },

        // ── Date Helpers ──────────────────────────────────────────────

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
            this.mediaPage = 1;
            this.mediaPool = [];
            this.mediaHasMore = false;
            this.loadingPool = true;
            try {
                const result = await this.$wire.getMediaPool(this.showAllMedia, this.mediaSearch.trim(), 1);
                this.mediaPool = result.items || [];
                this.mediaHasMore = result.has_more || false;
            } catch (err) {
                console.error('Failed to load media pool:', err);
                this.mediaPool = [];
            } finally {
                this.loadingPool = false;
            }
        },

        async loadMoreMedia() {
            if (!this.mediaHasMore || this.loadingMorePool || this.loadingPool) { return; }
            this.loadingMorePool = true;
            try {
                this.mediaPage++;
                const result = await this.$wire.getMediaPool(this.showAllMedia, this.mediaSearch.trim(), this.mediaPage);
                this.mediaPool = [...this.mediaPool, ...(result.items || [])];
                this.mediaHasMore = result.has_more || false;
            } catch (err) {
                console.error('Failed to load more media:', err);
                this.mediaPage--;
            } finally {
                this.loadingMorePool = false;
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
            if (this.showAllMedia) {
                // Server-side search already applied; just return the loaded pool.
                return this.mediaPool;
            }
            if (!this.mediaSearch.trim()) {
                return this.mediaPool;
            }
            const term = this.mediaSearch.toLowerCase().trim();
            return this.mediaPool.filter(item =>
                item.title.toLowerCase().includes(term)
            );
        },

        // ── Display Helpers ──────────────────────────────────────────

        getTypeColor(contentableType) {
            if (contentableType && contentableType.includes('Episode')) {
                return 'border-blue-300 dark:border-blue-600 bg-blue-50 dark:bg-blue-900/30';
            }
            return 'border-purple-300 dark:border-purple-600 bg-purple-50 dark:bg-purple-900/30';
        },

        getTypeAccent(contentableType) {
            if (contentableType && contentableType.includes('Episode')) {
                return 'text-blue-600 dark:text-blue-400';
            }
            return 'text-purple-600 dark:text-purple-400';
        },

        getTypeBadge(contentableType) {
            if (contentableType && contentableType.includes('Episode')) {
                return 'bg-blue-100 dark:bg-blue-800/50 text-blue-700 dark:text-blue-300';
            }
            return 'bg-purple-100 dark:bg-purple-800/50 text-purple-700 dark:text-purple-300';
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

        formatTimeRange(prog) {
            const startH = prog.start_hour ?? 0;
            const startM = prog.start_minute ?? 0;
            const endH = prog.end_hour ?? 0;
            const endM = prog.end_minute ?? 0;
            return `${this.formatTimeLabel(startH, startM)} – ${this.formatTimeLabel(endH, endM)}`;
        },

        formatTimeLabel(hour, minute) {
            const period = hour < 12 ? 'AM' : 'PM';
            const displayHour = hour === 0 ? 12 : hour > 12 ? hour - 12 : hour;
            const displayMinute = String(minute).padStart(2, '0');
            return `${displayHour}:${displayMinute} ${period}`;
        },

        /**
         * Calculate the gap (in seconds) between two adjacent programmes.
         * Returns null if this is the first programme.
         */
        gapBefore(index) {
            if (index === 0) return null;
            const prev = this.programmes[index - 1];
            const curr = this.programmes[index];
            if (!prev || !curr) return null;

            const prevEnd = new Date(prev.end_time).getTime();
            const currStart = new Date(curr.start_time).getTime();
            const diffSeconds = Math.round((currStart - prevEnd) / 1000);
            return diffSeconds;
        },

        formatGap(seconds) {
            if (seconds === null || seconds === undefined) return '';
            if (seconds === 0) return 'No gap';
            if (seconds < 0) {
                const abs = Math.abs(seconds);
                return `Overlap: ${this.formatDuration(abs)}`;
            }
            return `Gap: ${this.formatDuration(seconds)}`;
        },

        // ── Move Up / Down ───────────────────────────────────────────

        async moveUp(index) {
            if (index <= 0) return;

            // Swap locally for instant feedback
            const temp = this.programmes[index];
            this.programmes[index] = this.programmes[index - 1];
            this.programmes[index - 1] = temp;

            // Trigger Alpine reactivity
            this.programmes = [...this.programmes];

            // Send new order to backend
            const orderedIds = this.programmes.map(p => p.id);
            await this.reorderProgrammes(orderedIds);
        },

        async moveDown(index) {
            if (index >= this.programmes.length - 1) return;

            // Swap locally for instant feedback
            const temp = this.programmes[index];
            this.programmes[index] = this.programmes[index + 1];
            this.programmes[index + 1] = temp;

            // Trigger Alpine reactivity
            this.programmes = [...this.programmes];

            // Send new order to backend
            const orderedIds = this.programmes.map(p => p.id);
            await this.reorderProgrammes(orderedIds);
        },

        // ── Pool Drag → List ─────────────────────────────────────────

        handlePoolDragStart(event, item) {
            this.poolDragItem = item;
            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/plain', JSON.stringify({
                source: 'pool',
                contentable_type: item.contentable_type,
                contentable_id: item.contentable_id,
                duration_seconds: item.duration_seconds,
            }));
        },

        handleListDragOver(event) {
            if (this.poolDragItem) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
            }
        },

        async handleListDrop(event) {
            event.preventDefault();
            this.rowDragOverIndex = null;
            if (this.poolDragItem) {
                const item = this.poolDragItem;
                this.poolDragItem = null;
                await this.appendToEnd(item);
            }
        },

        // ── Row Drag (reorder) + Row Drop Target (pool insert) ───────

        handleRowDragStart(event, index) {
            this.rowDragIndex = index;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', JSON.stringify({ source: 'row', index }));
        },

        handleRowDragEnd() {
            this.rowDragIndex = null;
            this.rowDragOverIndex = null;
        },

        handleRowDragOver(event, index) {
            if (this.poolDragItem !== null || this.rowDragIndex !== null) {
                event.preventDefault();
                event.stopPropagation();
                this.rowDragOverIndex = index;
                event.dataTransfer.dropEffect = this.poolDragItem !== null ? 'copy' : 'move';
            }
        },

        async handleRowDrop(event, index) {
            event.preventDefault();
            event.stopPropagation();
            this.rowDragOverIndex = null;

            if (this.poolDragItem !== null) {
                const item = this.poolDragItem;
                this.poolDragItem = null;
                await this.insertAfterProgramme(this.programmes[index].id, item);
            } else if (this.rowDragIndex !== null && this.rowDragIndex !== index) {
                const fromIndex = this.rowDragIndex;
                this.rowDragIndex = null;

                const newOrder = [...this.programmes];
                const [moved] = newOrder.splice(fromIndex, 1);
                newOrder.splice(index, 0, moved);
                this.programmes = newOrder;

                await this.reorderProgrammes(this.programmes.map(p => p.id));
            } else {
                this.rowDragIndex = null;
            }
        },

        // ── Programme CRUD ───────────────────────────────────────────

        async reorderProgrammes(orderedIds) {
            this.loading = true;
            try {
                const result = await this.$wire.reorderProgrammes(orderedIds, this.currentDate, this.timezone);
                if (result.success && result.programmes) {
                    this.programmes = result.programmes;
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to reorder:', err);
                await this.loadSchedule();
            } finally {
                this.loading = false;
            }
        },

        async appendToEnd(item) {
            this.loading = true;
            try {
                const result = await this.$wire.addProgramme(
                    this.currentDate,
                    this.timezone,
                    item.contentable_type,
                    item.contentable_id,
                    item.duration_seconds || null
                );

                if (result.success && result.programmes) {
                    this.programmes = result.programmes;
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to append programme:', err);
            } finally {
                this.loading = false;
            }
        },

        async insertAfterProgramme(afterProgrammeId, item) {
            this.loading = true;
            try {
                const result = await this.$wire.insertAfterProgramme(
                    afterProgrammeId,
                    this.currentDate,
                    this.timezone,
                    item.contentable_type,
                    item.contentable_id,
                    item.duration_seconds || null
                );

                if (result.success && result.programmes) {
                    this.programmes = result.programmes;
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to insert programme:', err);
            } finally {
                this.loading = false;
            }
        },

        confirmRemoveProgramme(programmeId) {
            this.pendingRemoveId = programmeId;
            window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'schedule-remove-programme' } }));
        },

        async removeProgramme() {
            const programmeId = this.pendingRemoveId;
            this.pendingRemoveId = null;
            window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'schedule-remove-programme' } }));
            this.loading = true;
            try {
                const result = await this.$wire.removeProgramme(programmeId, this.currentDate, this.timezone);
                if (result.success && result.programmes) {
                    this.programmes = result.programmes;
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to remove programme:', err);
            } finally {
                this.loading = false;
            }
        },

        // ── Pin Time ─────────────────────────────────────────────────

        startEditPin(prog) {
            this.editingPinId = prog.id;
            this.editingPinTime = prog.pinned_start_time || '';
        },

        cancelEditPin() {
            this.editingPinId = null;
            this.editingPinTime = '';
        },

        async savePin(programmeId) {
            const time = this.editingPinTime || null;
            this.editingPinId = null;
            this.editingPinTime = '';

            this.loading = true;
            try {
                const result = await this.$wire.pinProgrammeTime(
                    programmeId,
                    time,
                    this.currentDate,
                    this.timezone
                );
                if (result.success && result.programmes) {
                    this.programmes = result.programmes;
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to pin time:', err);
            } finally {
                this.loading = false;
            }
        },

        async unpinTime(programmeId) {
            this.loading = true;
            try {
                const result = await this.$wire.pinProgrammeTime(
                    programmeId,
                    null,
                    this.currentDate,
                    this.timezone
                );
                if (result.success && result.programmes) {
                    this.programmes = result.programmes;
                    this.loadNowPlaying();
                }
            } catch (err) {
                console.error('Failed to unpin time:', err);
            } finally {
                this.loading = false;
            }
        },

        // ── Day Actions ──────────────────────────────────────────────

        clearCurrentDay() {
            window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'schedule-clear-day' } }));
        },

        async confirmClearDay() {
            window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'schedule-clear-day' } }));
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
            const firstOther = this.availableDates.find(d => d.value !== this.currentDate);
            this.copyTargetDate = firstOther ? firstOther.value : '';
            window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'schedule-copy-day' } }));
        },

        async copyDay() {
            if (!this.copyTargetDate || this.copyTargetDate === this.currentDate) { return; }

            const targetDate = this.copyTargetDate;
            window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'schedule-copy-day' } }));
            this.loading = true;
            try {
                const result = await this.$wire.copyDaySchedule(this.currentDate, targetDate, this.timezone);
                if (result.success) {
                    this.currentDate = targetDate;
                    await this.loadSchedule();
                }
            } catch (err) {
                console.error('Failed to copy day:', err);
            } finally {
                this.loading = false;
            }
        },

        applyWeeklyTemplate() {
            window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'schedule-apply-template' } }));
        },

        async confirmApplyTemplate() {
            window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'schedule-apply-template' } }));
            this.loading = true;
            try {
                await this.$wire.applyWeeklyTemplate();
            } catch (err) {
                console.error('Failed to apply weekly template:', err);
            } finally {
                this.loading = false;
            }
        },

        // ── Schedule Summary ─────────────────────────────────────────

        get totalDuration() {
            const total = this.programmes.reduce((sum, p) => sum + (p.duration_seconds || 0), 0);
            return this.formatDuration(total);
        },

        get scheduleEndTime() {
            if (this.programmes.length === 0) return '';
            const last = this.programmes[this.programmes.length - 1];
            return this.formatTimeLabel(last.end_hour, last.end_minute);
        },
    };
}

// Make scheduleBuilder globally accessible
window.scheduleBuilder = scheduleBuilder;
