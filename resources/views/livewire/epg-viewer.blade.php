<div 
    x-data="epgViewer({ 
        apiUrl: '{{ $route }}' 
    })"
    x-init="init(); loadEpgData()"
    x-on:beforeunload.window="destroy()"
    x-on:livewire:navigating.window="destroy()"
    x-on:refresh-epg-data.window="(e) => refreshEpgData(e.detail)"
    wire:ignore.self
>
    <div>
        <!-- Loading State -->
        <div x-show="loading" class="flex items-center justify-center p-8">
            <div class="flex items-center space-x-2">
                <svg class="animate-spin h-5 w-5 text-gray-500 dark:text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-sm text-gray-500 dark:text-gray-400">Loading EPG data...</span>
            </div>
        </div>

        <!-- Error State -->
        <div x-show="error && !loading" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="h-5 w-5 text-red-400 dark:text-red-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
                <p class="ml-2 text-sm text-red-700 dark:text-red-400" x-text="error"></p>
            </div>
        </div>

        <!-- EPG Content -->
        <div x-show="!loading && !error" class="space-y-6" wire:ignore.self>
            <!-- Date Navigation and Search -->
            <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-md p-3">
                <div class="flex flex-col gap-4">
                    <!-- Header Row -->
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <!-- Date Navigation -->
                        <div class="flex items-center justify-between sm:justify-start gap-2 sm:gap-4">
                            <x-filament::button 
                                icon="heroicon-m-chevron-left"
                                icon-position="before"
                                color="gray"
                                size="sm"
                                @click="previousDay()"
                            >
                                <span class="hidden sm:inline">Previous</span>
                                <span class="sm:hidden">Prev</span>
                            </x-filament::button>
                            
                            <div class="flex flex-col text-center sm:text-left">
                                <h3 class="text-base sm:text-lg font-medium text-gray-900 dark:text-gray-100 truncate" x-text="epgData?.epg?.name || epgData?.playlist?.name || 'EPG Viewer'"></h3>
                                <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400" x-text="formatDate(currentDate)"></p>
                            </div>
                            
                            <x-filament::button 
                                icon="heroicon-m-chevron-right"
                                icon-position="after"
                                color="gray"
                                size="sm"
                                @click="nextDay()"
                            >
                                <span class="hidden sm:inline">Next</span>
                                <span class="sm:hidden">Next</span>
                            </x-filament::button>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center justify-center sm:justify-end gap-2">
                            <x-filament::button
                                icon="heroicon-m-calendar"
                                icon-position="before"
                                color="gray"
                                size="sm"
                                x-show="!isToday()"
                                @click="goToToday()"
                            >
                                <span class="hidden sm:inline">Today</span>
                                <span class="sm:hidden">Today</span>
                            </x-filament::button>
                            <x-filament::button
                                icon="heroicon-m-clock"
                                icon-position="before"
                                color="gray"
                                size="sm"
                                x-show="isToday()"
                                @click="scrollToCurrentTime()"
                            >
                                <span class="hidden sm:inline">Now</span>
                                <span class="sm:hidden">Now</span>
                            </x-filament::button>
                        </div>
                    </div>

                    <!-- Search Bar -->
                    <div class="flex items-center space-x-2">
                        <div class="relative flex-1">
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="text" 
                                    x-model="searchTerm"
                                    @keydown="handleSearchKeydown($event)"
                                    placeholder="Search channels..."
                                />
                                <x-slot name="suffix">
                                    <!-- Clear Button -->
                                    <button 
                                        x-show="searchTerm.length > 0"
                                        @click="clearSearch()"
                                        class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                                        title="Clear search"
                                    >
                                        <x-heroicon-m-x-mark class="w-4 h-4" />
                                    </button>
                                    <!-- Search Button -->
                                    <button 
                                        @click="performSearch()"
                                        :disabled="!searchTerm.trim()"
                                        :class="searchTerm.trim() ? 'text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300' : 'text-gray-300 dark:text-gray-600 cursor-not-allowed'"
                                        class="p-1 transition-colors"
                                        title="Search"
                                    >
                                        <x-heroicon-m-magnifying-glass class="w-4 h-4" />
                                    </button>
                                </x-slot>
                            </x-filament::input.wrapper>
                        </div>
                    </div>
                </div>
            </div>

            <!-- EPG Grid Container -->
            <div class="bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 dark:bg-gray-900 rounded-md p-0 overflow-hidden relative" 
                 :style="isMobile ? 'height: 500px; padding-bottom: 48px;' : 'height: 600px; padding-bottom: 48px;'"
            >
                 <!-- Loading More Overlay -->
                <div 
                    x-show="loadingMore" 
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    wire:ignore
                    class="absolute top-0 left-0 right-0 z-50 bg-indigo-50 dark:bg-indigo-900 border-b border-indigo-200 dark:border-indigo-800 px-4 py-2"
                >
                    <div class="flex items-center justify-center space-x-2">
                        <svg class="animate-spin h-4 w-4 text-indigo-500 dark:text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-sm text-indigo-700 dark:text-indigo-300">Loading more channels...</span>
                    </div>
                </div>
                <!-- Time Header -->
                <div class="sticky top-0 z-10 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600">
                    <div class="flex">
                        <!-- Channel Column Header -->
                        <div :class="isMobile ? 'w-32' : 'w-60'" class="px-2 md:px-4 py-3 border-r border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-800">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span :class="isMobile ? 'text-xs' : 'text-sm'" class="font-medium text-gray-900 dark:text-gray-100">
                                        <span x-show="!isMobile">Channels</span>
                                        <span x-show="isMobile">Ch.</span>
                                    </span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 ml-1" x-text="`(${Object.keys(epgData?.channels || {}).length})`"></span>
                                </div>
                                <!-- Search Status Indicator -->
                                <div x-show="isSearchActive && !isMobile" class="flex items-center space-x-1">
                                    <x-heroicon-m-magnifying-glass class="w-3 h-3 text-indigo-500 dark:text-indigo-400" />
                                    <span class="text-xs text-indigo-600 dark:text-indigo-400" x-text="'&quot;' + searchTerm + '&quot;'"></span>
                                </div>
                            </div>
                        </div>
                        <!-- Time Slots Header (Scrollable) -->
                        <div class="flex-1 relative overflow-hidden">
                            <div 
                                class="overflow-x-auto time-header-scroll" 
                                @scroll="document.querySelector('.timeline-scroll').scrollLeft = $el.scrollLeft"
                                style="scrollbar-width: none; -ms-overflow-style: none;"
                            >
                                <div class="flex relative" style="width: 2400px;"> <!-- 24 hours * 100px per hour -->
                                    <template x-for="hour in timeSlots" :key="hour">
                                        <div class="px-1 md:px-2 py-3 border-r border-gray-200 dark:border-gray-600 text-center bg-gray-100 dark:bg-gray-800" style="width: 100px;">
                                            <span class="font-medium text-xs text-gray-700 dark:text-gray-300" x-text="formatTime(hour)"></span>
                                        </div>
                                    </template>
                                    <!-- Current time indicator (moves with content) -->
                                    <div 
                                        x-show="isToday() && currentTimePosition >= 0"
                                        class="absolute top-0 bottom-0 w-0.5 bg-red-500 z-10"
                                        :style="`left: ${currentTimePosition}px;`"
                                    >
                                        <div class="absolute -top-1 -left-1 w-2 h-2 bg-red-500 rounded-full"></div>
                                    </div>
                                </div>
                            </div>
                            <style>
                                .time-header-scroll::-webkit-scrollbar {
                                    display: none;
                                }
                            </style>
                        </div>
                    </div>
                </div>

                <!-- Scrollable Content Area -->
                <div class="flex h-full overflow-hidden" x-data="{
                    virtualScrollTop: 0,
                    get itemHeight() { return isMobile ? 48 : 60; },
                    get containerHeight() { return isMobile ? 452 : 552; },
                    get totalChannels() { return Object.keys(epgData?.channels || {}).length; },
                    get startIndex() { return Math.max(0, Math.floor(this.virtualScrollTop / this.itemHeight) - 5); },
                    get endIndex() { return Math.min(this.totalChannels, this.startIndex + Math.ceil(this.containerHeight / this.itemHeight) + 15); },
                    get visibleChannels() {
                        if (!epgData?.channels) return [];
                        const channelEntries = Object.entries(epgData.channels);
                        return channelEntries.slice(this.startIndex, this.endIndex).map(([id, channel], index) => ({
                            id,
                            channel,
                            absoluteIndex: this.startIndex + index,
                            top: (this.startIndex + index) * this.itemHeight
                        }));
                    }
                }">
                    <!-- Channel List (Virtual Scrolled) -->
                    <div :class="isMobile ? 'w-32' : 'w-60'" class="border-r border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 overflow-hidden">
                        <div 
                            class="overflow-y-auto overflow-x-hidden h-full"
                            @scroll="
                                $refs.timelineScroll.scrollTop = $el.scrollTop;
                                virtualScrollTop = $el.scrollTop;
                                // Check if we need to load more data
                                if ($el.scrollTop + $el.clientHeight >= $el.scrollHeight - 200 && hasMore && !loadingMore) {
                                    loadMoreData();
                                }
                            "
                            x-ref="channelScroll"
                        >
                            <!-- Virtual scroll container with proper height -->
                            <div class="relative" :style="`height: ${totalChannels * itemHeight}px;`">
                                <!-- Only render visible items -->
                                <template x-for="item in visibleChannels" :key="item.id">
                                    <div 
                                        :class="isMobile ? 'px-2 py-2' : 'px-4 py-3'"
                                        class="absolute w-full border-b border-gray-100 dark:border-gray-600 flex items-center space-x-2 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors group"
                                        :style="`top: ${item.top}px; height: ${itemHeight}px;`"
                                    >
                                        <div class="flex-shrink-0">
                                            <img 
                                                :src="item.channel.icon || '/placeholder.png'" 
                                                :alt="item.channel.display_name"
                                                :class="isMobile ? 'w-6 h-6' : 'w-8 h-8'"
                                                class="rounded object-contain"
                                                onerror="this.src='/placeholder.png'"
                                            >
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p :class="isMobile ? 'text-xs' : 'text-sm'" class="font-medium text-gray-900 dark:text-gray-100 truncate" x-text="item.channel.display_name" x-tooltip="item.channel.display_name"></p>
                                            <p x-show="!isMobile" class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="item.id"></p>
                                        </div>
                                        <!-- Action Buttons -->
                                        <div x-show="!isMobile && (item.channel.database_id || item.channel.url)" 
                                            class="absolute p-2 rounded-xl bg-white/90 shadow-sm dark:bg-gray-800/90 right-1 top-1/2 -translate-y-1/2 flex space-x-1 transform translate-x-8 opacity-0 group-hover:translate-x-0 group-hover:opacity-100 group-focus-within:translate-x-0 group-focus-within:opacity-100 transition-all duration-200 ease-in-out">
                                            <!-- Edit Button -->
                                            <button 
                                                x-show="item.channel.database_id"
                                                @click.stop="
                                                    if (!modalLoading) {
                                                        modalLoading = true;
                                                        $wire.openChannelEdit(item.channel.database_id);
                                                        setTimeout(() => { modalLoading = false; }, 1000);
                                                    }
                                                "
                                                :disabled="modalLoading"
                                                class="p-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-900/20 rounded-full transition-colors disabled:opacity-50"
                                                title="Edit Channel"
                                            >
                                                <x-heroicon-s-pencil class="w-4 h-4" />
                                            </button>
                                            <!-- Play Button -->
                                            <button 
                                                x-show="item.channel.url"
                                                @click.stop="
                                                    window.dispatchEvent(new CustomEvent('openFloatingStream', { detail: item.channel }))
                                                "
                                                class="p-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-900/20 rounded-full transition-colors"
                                                title="Play Stream in Floating Window"
                                            >
                                                <x-heroicon-s-play class="w-4 h-4" />
                                            </button>
                                        </div>
                                        <!-- Mobile action indicator -->
                                        <div x-show="isMobile && (item.channel.database_id || item.channel.url)" 
                                            class="flex-shrink-0 text-gray-400 dark:text-gray-500">
                                            <x-heroicon-m-ellipsis-horizontal class="w-4 h-4" />
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <!-- No Results Message -->
                            <div x-show="isSearchActive && Object.keys(epgData?.channels || {}).length === 0 && !loadingMore && !loading" :class="isMobile ? 'px-2 py-6' : 'px-4 py-8'" class="text-center">
                                <div class="flex flex-col items-center space-y-2">
                                    <x-heroicon-m-magnifying-glass :class="isMobile ? 'w-6 h-6' : 'w-8 h-8'" class="text-gray-400 dark:text-gray-500" />
                                    <div :class="isMobile ? 'text-xs' : 'text-sm'" class="font-medium text-gray-600 dark:text-gray-400">No channels found</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400" x-text="'No results for &quot;' + searchTerm + '&quot;'"></div>
                                    <button 
                                        @click="clearSearch()"
                                        class="mt-2 text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 underline"
                                    >
                                        Clear search
                                    </button>
                                </div>
                            </div>

                            <!-- Loading indicator at bottom when more data is being loaded -->
                            <div x-show="hasMore && !loadingMore" :class="isMobile ? 'px-2 py-2' : 'px-4 py-3'" class="text-center">
                                <div class="text-xs text-gray-500 dark:text-gray-400">Scroll down for more channels...</div>
                            </div>
                        </div>
                    </div>

                    <!-- Programme Timeline (Virtual Scrolled) -->
                    <div 
                        class="flex-1 overflow-auto relative timeline-scroll"
                        @scroll="
                            $refs.channelScroll.scrollTop = $el.scrollTop;
                            document.querySelector('.time-header-scroll').scrollLeft = $el.scrollLeft;
                            virtualScrollTop = $el.scrollTop;
                        "
                        x-ref="timelineScroll"
                    >
                        <div class="relative overflow-hidden" style="width: 2400px;"> <!-- 24 hours * 100px per hour -->
                            <!-- Current time indicator for programme area -->
                            <div 
                                x-show="isToday() && currentTimePosition >= 0"
                                class="absolute top-0 bottom-0 w-0.5 bg-red-500 z-30 pointer-events-none"
                                :style="`left: ${currentTimePosition}px; height: ${totalChannels * itemHeight}px;`"
                            ></div>
                            
                            <!-- Virtual scroll container for programmes -->
                            <div class="relative" :style="`height: ${totalChannels * itemHeight}px;`">
                                <template x-for="item in visibleChannels" :key="item.id">
                                    <div 
                                        class="absolute w-full border-b border-gray-100 dark:border-gray-600" 
                                        :style="`top: ${item.top}px; height: ${itemHeight}px;`"
                                    >
                                        <!-- Time grid background -->
                                        <div class="absolute inset-0 flex">
                                            <template x-for="hour in timeSlots" :key="`${item.id}-${hour}`">
                                                <div class="border-r border-gray-200 dark:border-gray-600" style="width: 100px;"></div>
                                            </template>
                                        </div>
                                        
                                        <!-- Programme blocks -->
                                        <div class="absolute inset-0">
                                            <template x-for="(programme, programmeIndex) in item.channel.programmes" :key="`${item.id}-${programmeIndex}-${programme.start || 'nostart'}-${programme.stop || 'nostop'}-${(programme.title || 'notitle').replace(/[^a-zA-Z0-9]/g, '')}`">
                                                <div 
                                                    class="absolute rounded shadow-sm cursor-pointer group transition-all duration-200"
                                                    :class="getProgrammeColorClass(programme)"
                                                    :style="`${getProgrammeStyle(programme)}; top: 2px; bottom: 2px;`"
                                                    x-tooltip.html="getTooltipContent(programme)"
                                                >
                                                    <div class="h-full p-2 overflow-hidden flex flex-col justify-center">
                                                        <div class="font-medium text-xs text-gray-900 dark:text-gray-100 truncate leading-tight" x-text="programme.title"></div>
                                                        <div class="text-xs text-gray-600 dark:text-gray-300 truncate" x-text="formatProgrammeTime(programme)"></div>
                                                        <div x-show="programme.new" class="absolute top-0.5 right-0.5 bg-gray-500 text-white text-xs px-1 rounded-xl opacity-100" style="font-size: 10px; line-height: 1;">
                                                            New
                                                        </div>
                                                    </div>
                                                </div>
                                                {{-- <x-filament::modal width="2xl">
                                                    <x-slot name="trigger">
                                                        <div 
                                                            class="absolute rounded shadow-sm cursor-pointer group transition-all duration-200"
                                                            :class="getProgrammeColorClass(programme)"
                                                            :style="`${getProgrammeStyle(programme)}; top: 2px; bottom: 2px;`"
                                                            x-tooltip.html="getTooltipContent(programme)"
                                                        >
                                                            <div class="h-full p-2 overflow-hidden flex flex-col justify-center">
                                                                <div class="font-medium text-xs text-gray-900 dark:text-gray-100 truncate leading-tight" x-text="programme.title"></div>
                                                                <div class="text-xs text-gray-600 dark:text-gray-300 truncate" x-text="formatProgrammeTime(programme)"></div>
                                                                <div x-show="programme.new" class="absolute top-0.5 right-0.5 bg-gray-500 text-white text-xs px-1 rounded-xl opacity-100" style="font-size: 10px; line-height: 1;">
                                                                    New
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </x-slot>

                                                    <x-slot name="heading">
                                                        <span x-text="programme.title"></span>
                                                    </x-slot>

                                                    <div class="space-y-1">
                                                        <div>
                                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Time:</span>
                                                            <span class="text-sm text-gray-900 dark:text-gray-100 ml-2" x-text="formatProgrammeTime(programme)"></span>
                                                        </div>
                                                        <div x-show="programme.category">
                                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Category:</span>
                                                            <span class="text-sm text-gray-900 dark:text-gray-100 ml-2" x-text="programme.category"></span>
                                                        </div>
                                                        <div x-show="programme.episode_num">
                                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Episode:</span>
                                                            <span class="text-sm text-gray-900 dark:text-gray-100 ml-2" x-text="getProgrammeSeasonEpisode(programme)"></span>
                                                        </div>
                                                        <div x-show="programme.new">
                                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">New Episode</span>
                                                            <span class="text-sm text-gray-900 dark:text-gray-100 ml-2"><x-heroicon-s-check class="w-4 h-4 inline-block" /></span>
                                                        </div>
                                                        <div x-show="programme.desc" class="space-y-2">
                                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Description</span>
                                                            <p class="text-sm text-gray-600 dark:text-gray-400" x-text="programme.desc"></p>
                                                        </div>
                                                    </div>
                                                </x-filament::modal> --}}
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Floating Stream Players -->
        <x-floating-stream-players />
        
        <!-- Filament Actions Modals -->
        <x-filament-actions::modals />
    </div>
</div>
