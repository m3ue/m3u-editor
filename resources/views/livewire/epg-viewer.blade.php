<div
    x-data="epgViewer({ 
        apiUrl: '{{ $route }}',
        groupsApiUrl: {{ $groupsApiUrl ? "'" . $groupsApiUrl . "'" : 'null' }},
        vod: {{ $vod ? 'true' : 'false' }},
        username: '{{ $username }}',
        password: '{{ $password }}'
    })"
    x-init="
        init();
        loadEpgData();
        loadGroups();
    "
    x-on:beforeunload.window="destroy()"
    x-on:livewire:navigating.window="destroy()"
    x-on:refresh-epg-data.window="(e) => refreshEpgData(e.detail)"
    wire:ignore.self
>
    <div>
        <!-- Loading State -->
        <div x-show="loading" class="flex items-center justify-center p-8">
            <div class="flex items-center space-x-2">
                <svg
                    class="h-5 w-5 animate-spin text-indigo-500 dark:text-indigo-400"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path
                        class="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                    ></path>
                </svg>
                <span class="text-sm text-gray-500 dark:text-gray-400">Loading EPG data...</span>
            </div>
        </div>

        <!-- Error State -->
        <div
            x-show="error && ! loading"
            class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20"
        >
            <div class="flex items-center">
                <svg
                    class="h-5 w-5 text-red-400 dark:text-red-500"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                >
                    <path
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                        clip-rule="evenodd"
                    />
                </svg>
                <p class="ml-2 text-sm text-red-700 dark:text-red-400" x-text="error"></p>
            </div>
        </div>

        <!-- EPG Content -->
        <div x-show="! loading && ! error" class="space-y-6" wire:ignore.self>
            <!-- Date Navigation and Search -->
            <div class="rounded-md bg-white p-3 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex flex-col gap-4">
                    <!-- Header Row -->
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <!-- Date Navigation -->
                        <div class="flex items-center justify-between gap-2 sm:justify-start sm:gap-4">
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
                                <h3
                                    class="truncate text-base font-medium text-gray-900 sm:text-lg dark:text-gray-100"
                                    x-text="epgData?.epg?.name || epgData?.playlist?.name || 'EPG Viewer'"
                                ></h3>
                                <p
                                    class="text-xs text-gray-500 sm:text-sm dark:text-gray-400"
                                    x-text="formatDate(currentDate)"
                                ></p>
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
                        <div class="flex items-center justify-center gap-2 sm:justify-end">
                            <x-filament::button
                                icon="heroicon-m-calendar"
                                icon-position="before"
                                color="gray"
                                size="sm"
                                x-show="! isToday()"
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
                    <div class="flex items-center gap-2">
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
                                        class="p-1 text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-300"
                                        title="Clear search"
                                    >
                                        <x-heroicon-m-x-mark class="h-4 w-4" />
                                    </button>
                                    <!-- Search Button -->
                                    <button
                                        @click="performSearch()"
                                        :disabled="! searchTerm.trim()"
                                        :class="searchTerm.trim()
                                            ? 'text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300'
                                            : 'text-gray-300 dark:text-gray-600 cursor-not-allowed'"
                                        class="p-1 transition-colors"
                                        title="Search"
                                    >
                                        <x-heroicon-m-magnifying-glass class="h-4 w-4" />
                                    </button>
                                </x-slot>
                            </x-filament::input.wrapper>
                        </div>
                    </div>

                    <!-- Group / Category Tabs -->
                    <div x-show="availableGroups.length > 0" class="relative" wire:ignore>
                        <div class="scrollbar-hide overflow-x-auto" style="scroll-behavior: smooth">
                            <div class="flex items-center gap-1.5 pb-0.5">
                                <!-- All tab -->
                                <button
                                    @click="selectGroup('')"
                                    :class="selectedGroup === ''
                                        ? 'bg-primary-600 text-white shadow-sm'
                                        : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'"
                                    class="flex-shrink-0 rounded-full px-3 py-1.5 text-xs font-medium whitespace-nowrap transition-colors"
                                >
                                    All
                                </button>
                                <!-- Group tabs -->
                                <template x-for="group in availableGroups" :key="group">
                                    <button
                                        @click="selectGroup(group)"
                                        :class="selectedGroup === group
                                            ? 'bg-primary-600 text-white shadow-sm'
                                            : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'"
                                        class="flex-shrink-0 rounded-full px-3 py-1.5 text-xs font-medium whitespace-nowrap transition-colors"
                                        x-text="group"
                                    ></button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- EPG Grid Container -->
            <div
                class="relative overflow-hidden rounded-md bg-white p-0 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
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
                    class="absolute top-0 right-0 left-0 z-50 border-b border-indigo-200 bg-indigo-50 px-4 py-2 dark:border-indigo-800 dark:bg-indigo-900"
                >
                    <div class="flex items-center justify-center space-x-2">
                        <svg
                            class="h-4 w-4 animate-spin text-indigo-500 dark:text-indigo-400"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            ></path>
                        </svg>
                        <span class="text-sm text-indigo-700 dark:text-indigo-300">Loading channels...</span>
                    </div>
                </div>
                <!-- Time Header -->
                <div class="sticky top-0 z-10 border-b border-gray-200 bg-gray-50 dark:border-gray-600 dark:bg-gray-700">
                    <div class="flex">
                        <!-- Channel Column Header -->
                        <div
                            :class="isMobile ? 'w-32' : 'w-60'"
                            class="border-r border-gray-200 bg-gray-100 px-2 py-3 md:px-4 dark:border-gray-600 dark:bg-gray-800"
                        >
                            <div class="flex items-center justify-between">
                                <div>
                                    <span
                                        :class="isMobile ? 'text-xs' : 'text-sm'"
                                        class="font-medium text-gray-900 dark:text-gray-100"
                                    >
                                        <span x-show="! isMobile">Channels</span>
                                        <span x-show="isMobile">Ch.</span>
                                    </span>
                                    <span
                                        class="ml-1 text-xs text-gray-500 dark:text-gray-400"
                                        x-text="`(${filteredChannelOrder.length})`"
                                    ></span>
                                </div>
                                <!-- Search Status Indicator -->
                                <div x-show="isSearchActive && ! isMobile" class="flex items-center space-x-1">
                                    <x-heroicon-m-magnifying-glass class="h-3 w-3 text-indigo-500 dark:text-indigo-400" />
                                    <span
                                        class="text-xs text-indigo-600 dark:text-indigo-400"
                                        x-text="'&quot;' + searchTerm + '&quot;'"
                                    ></span>
                                </div>
                            </div>
                        </div>
                        <!-- Time Slots Header (Scrollable) -->
                        <div class="relative flex-1 overflow-hidden">
                            <div
                                class="time-header-scroll overflow-x-auto"
                                @scroll="document.querySelector('.timeline-scroll').scrollLeft = $el.scrollLeft"
                                style="scrollbar-width: none; -ms-overflow-style: none"
                            >
                                <div class="relative flex" style="width: 2400px">
                                    <!-- 24 hours * 100px per hour -->
                                    <template x-for="hour in timeSlots" :key="hour">
                                        <div
                                            class="border-r border-gray-200 bg-gray-100 px-1 py-3 text-center md:px-2 dark:border-gray-600 dark:bg-gray-800"
                                            style="width: 100px"
                                        >
                                            <span
                                                class="text-xs font-medium text-gray-700 dark:text-gray-300"
                                                x-text="formatTime(hour)"
                                            ></span>
                                        </div>
                                    </template>
                                    <!-- Current time indicator (moves with content) -->
                                    <div
                                        x-show="isToday() && currentTimePosition >= 0"
                                        class="absolute top-0 bottom-0 z-10 w-0.5 bg-red-500"
                                        :style="`left: ${currentTimePosition}px;`"
                                    >
                                        <div class="absolute -top-1 -left-1 h-2 w-2 rounded-full bg-red-500"></div>
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
                <div
                    class="flex h-full overflow-hidden pb-[2.8rem]"
                    x-data="{
                        virtualScrollTop: 0,
                        get itemHeight() {
                            return isMobile ? 48 : 60;
                        },
                        get containerHeight() {
                            return isMobile ? 452 : 552;
                        },
                        get totalChannels() {
                            return filteredChannelOrder.length;
                        },
                        get startIndex() {
                            return Math.max(0, Math.floor(this.virtualScrollTop / this.itemHeight) - 5);
                        },
                        get endIndex() {
                            return Math.min(
                                this.totalChannels,
                                this.startIndex + Math.ceil(this.containerHeight / this.itemHeight) + 15,
                            );
                        },
                        get visibleChannels() {
                            if (! epgData?.channels) return [];
                            const orderedIds = filteredChannelOrder.slice(this.startIndex, this.endIndex);
                            return orderedIds.map((id, index) => ({
                                id,
                                channel: epgData.channels[id],
                                absoluteIndex: this.startIndex + index,
                                top: (this.startIndex + index) * this.itemHeight,
                            }));
                        },
                    }"
                >
                    <!-- Channel List (Virtual Scrolled) -->
                    <div
                        :class="isMobile ? 'w-32' : 'w-60'"
                        class="overflow-hidden border-r border-gray-200 bg-gray-50 dark:border-gray-600 dark:bg-gray-700"
                    >
                        <div
                            class="h-full overflow-x-hidden overflow-y-auto"
                            @scroll="
                                $refs.timelineScroll.scrollTop = $el.scrollTop;
                                virtualScrollTop = $el.scrollTop;
                                if (
                                    isScrollMode &&
                                    $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 200 &&
                                    hasMore &&
                                    ! loadingMore
                                ) {
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
                                        class="group absolute flex w-full items-center space-x-2 border-b border-gray-100 transition-colors hover:bg-gray-100 dark:border-gray-600 dark:hover:bg-gray-600"
                                        :style="`top: ${item.top}px; height: ${itemHeight}px;`"
                                    >
                                        <div class="flex-shrink-0">
                                            <img
                                                :src="item.channel.icon || '/placeholder.png'"
                                                :alt="item.channel.display_name"
                                                :class="isMobile ? 'w-6 h-6' : 'w-8 h-8'"
                                                class="rounded object-contain"
                                                onerror="this.src = '/placeholder.png'"
                                            />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p
                                                :class="isMobile ? 'text-xs' : 'text-sm'"
                                                class="truncate font-medium text-gray-900 dark:text-gray-100"
                                                x-text="item.channel.display_name"
                                                x-tooltip="item.channel.display_name"
                                            ></p>
                                            <p
                                                x-show="! isMobile"
                                                class="truncate text-xs text-gray-500 dark:text-gray-400"
                                                x-text="item.id"
                                            ></p>
                                        </div>
                                        <!-- Action Buttons -->
                                        @if (! $viewOnly)
                                            <div
                                                x-show="! isMobile && (item.channel.database_id || item.channel.url)"
                                                class="absolute top-1/2 right-1 flex translate-x-8 -translate-y-1/2 transform space-x-1 rounded-xl bg-white/90 p-2 opacity-0 shadow-sm transition-all duration-200 ease-in-out group-focus-within:translate-x-0 group-focus-within:opacity-100 group-hover:translate-x-0 group-hover:opacity-100 dark:bg-gray-800/90"
                                            >
                                                <!-- Edit Button -->
                                                <button
                                                    x-show="item.channel.database_id"
                                                    @click.stop="
                                                        if (! modalLoading) {
                                                            modalLoading = true;
                                                            $wire.openChannelEdit(item.channel.database_id);
                                                            setTimeout(() => {
                                                                modalLoading = false;
                                                            }, 1000);
                                                        }
                                                    "
                                                    :disabled="modalLoading"
                                                    class="rounded-full p-2 text-gray-600 transition-colors hover:bg-gray-50 hover:text-gray-800 disabled:opacity-50 dark:text-gray-400 dark:hover:bg-gray-900/20 dark:hover:text-gray-200"
                                                    title="Edit Channel"
                                                >
                                                    <x-heroicon-s-pencil class="h-4 w-4" />
                                                </button>
                                        @endif
                                        <!-- Play Button -->
                                        <button
                                            x-show="item.channel.url"
                                            @click.stop="
                                                window.dispatchEvent(
                                                    new CustomEvent('openFloatingStream', { detail: item.channel }),
                                                )
                                            "
                                            class="rounded-full p-2 text-gray-600 transition-colors hover:bg-gray-50 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-gray-900/20 dark:hover:text-gray-200"
                                            title="Play Stream in Floating Window"
                                        >
                                            <x-heroicon-s-play class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <!-- Mobile action indicator -->
                                    <div
                                        x-show="isMobile && (item.channel.database_id || item.channel.url)"
                                        class="flex-shrink-0 text-gray-400 dark:text-gray-500"
                                    >
                                        <x-heroicon-m-ellipsis-horizontal class="h-4 w-4" />
                                    </div>
                            </div>
                            </template>
                        </div>

                        <!-- Scroll mode: more channels indicator -->
                        <div
                            x-show="isScrollMode && hasMore && ! loadingMore"
                            :class="isMobile ? 'px-2 py-2' : 'px-4 py-3'"
                            class="text-center"
                        >
                            <div class="text-xs text-gray-500 dark:text-gray-400">Scroll down for more channels...</div>
                        </div>

                        <!-- No Results Message -->
                        <div
                            x-show="isSearchActive && channelOrder.length === 0 && ! loadingMore && ! loading"
                            :class="isMobile ? 'px-2 py-6' : 'px-4 py-8'"
                            class="text-center"
                        >
                            <div class="flex flex-col items-center space-y-2">
                                <x-heroicon-m-magnifying-glass
                                    :class="isMobile ? 'w-6 h-6' : 'w-8 h-8'"
                                    class="text-gray-400 dark:text-gray-500"
                                />
                                <div
                                    :class="isMobile ? 'text-xs' : 'text-sm'"
                                    class="font-medium text-gray-600 dark:text-gray-400"
                                >
                                    No channels found
                                </div>
                                <div
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                    x-text="'No results for &quot;' + searchTerm + '&quot;'"
                                ></div>
                                <button
                                    @click="clearSearch()"
                                    class="mt-2 text-xs text-indigo-600 underline hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                >
                                    Clear search
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Programme Timeline (Virtual Scrolled) -->
                <div
                    class="timeline-scroll relative flex-1 overflow-auto"
                    @scroll="
                        $refs.channelScroll.scrollTop = $el.scrollTop;
                        document.querySelector('.time-header-scroll').scrollLeft = $el.scrollLeft;
                        virtualScrollTop = $el.scrollTop;
                    "
                    x-ref="timelineScroll"
                >
                    <div class="relative overflow-hidden" style="width: 2400px">
                        <!-- 24 hours * 100px per hour -->
                        <!-- Current time indicator for programme area -->
                        <div
                            x-show="isToday() && currentTimePosition >= 0"
                            class="pointer-events-none absolute top-0 bottom-0 z-30 w-0.5 bg-red-500"
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
                                            <div
                                                class="border-r border-gray-200 dark:border-gray-600"
                                                style="width: 100px"
                                            ></div>
                                        </template>
                                    </div>

                                    <!-- Programme blocks -->
                                    <div class="absolute inset-0">
                                        <template
                                            x-for="(programme, programmeIndex) in item.channel.programmes"
                                            :key="`${item.id}-${programmeIndex}-${programme.start || 'nostart'}-${programme.stop || 'nostop'}-${(programme.title || 'notitle').replace(/[^a-zA-Z0-9]/g, '')}`"
                                        >
                                            <div
                                                class="group/prog absolute cursor-pointer rounded shadow-sm transition-all duration-200"
                                                :class="getProgrammeColorClass(programme)"
                                                :style="`${getProgrammeStyle(programme)}; top: 2px; bottom: 2px;`"
                                                x-tooltip.html="getTooltipContent(programme)"
                                            >
                                                <div class="flex h-full flex-col justify-center overflow-hidden p-2">
                                                    <div
                                                        class="truncate text-xs leading-tight font-medium text-gray-900 dark:text-gray-100"
                                                        x-text="programme.title"
                                                    ></div>
                                                    <div
                                                        class="truncate text-xs text-gray-600 dark:text-gray-300"
                                                        x-text="formatProgrammeTime(programme)"
                                                    ></div>
                                                    <div
                                                        x-show="programme.new"
                                                        class="absolute top-0.5 right-0.5 rounded-xl bg-gray-500 px-1 text-xs text-white opacity-100"
                                                        style="font-size: 10px; line-height: 1"
                                                    >
                                                        New
                                                    </div>
                                                    @if (! $viewOnly && $dvrEnabled)
                                                        <!-- DVR Record Button (visible on hover) -->
                                                        <button
                                                            x-show="item.channel.database_id"
                                                            @click.stop="
                                                                $wire.openScheduleProgramme(
                                                                    programme,
                                                                    item.channel.database_id,
                                                                )
                                                            "
                                                            class="absolute right-0.5 bottom-0.5 rounded-full bg-white/80 p-1 text-red-600 opacity-0 transition-opacity duration-150 group-hover/prog:opacity-100 hover:text-red-800 dark:bg-gray-800/80 dark:text-red-400 dark:hover:text-red-200"
                                                            title="{{ __('Schedule Recording') }}"
                                                        >
                                                            <x-heroicon-s-video-camera class="h-3 w-3" />
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                            {{-- <x-filament::modal width="2xl">
                                                    <x-slot name="trigger">
                                                        <div class="absolute rounded shadow-sm cursor-pointer group transition-all duration-200"
                                                            :class="getProgrammeColorClass(programme)"
                                                            :style="`${getProgrammeStyle(programme)}; top: 2px; bottom: 2px;`"
                                                            x-tooltip.html="getTooltipContent(programme)">
                                                            <div
                                                                class="h-full p-2 overflow-hidden flex flex-col justify-center">
                                                                <div class="font-medium text-xs text-gray-900 dark:text-gray-100 truncate leading-tight"
                                                                    x-text="programme.title"></div>
                                                                <div class="text-xs text-gray-600 dark:text-gray-300 truncate"
                                                                    x-text="formatProgrammeTime(programme)"></div>
                                                                <div x-show="programme.new"
                                                                    class="absolute top-0.5 right-0.5 bg-gray-500 text-white text-xs px-1 rounded-xl opacity-100"
                                                                    style="font-size: 10px; line-height: 1;">
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
                                                            <span
                                                                class="text-sm font-medium text-gray-700 dark:text-gray-300">Time:</span>
                                                            <span class="text-sm text-gray-900 dark:text-gray-100 ml-2"
                                                                x-text="formatProgrammeTime(programme)"></span>
                                                        </div>
                                                        <div x-show="programme.category">
                                                            <span
                                                                class="text-sm font-medium text-gray-700 dark:text-gray-300">Category:</span>
                                                            <span class="text-sm text-gray-900 dark:text-gray-100 ml-2"
                                                                x-text="programme.category"></span>
                                                        </div>
                                                        <div x-show="programme.episode_num">
                                                            <span
                                                                class="text-sm font-medium text-gray-700 dark:text-gray-300">Episode:</span>
                                                            <span class="text-sm text-gray-900 dark:text-gray-100 ml-2"
                                                                x-text="getProgrammeSeasonEpisode(programme)"></span>
                                                        </div>
                                                        <div x-show="programme.new">
                                                            <span
                                                                class="text-sm font-medium text-gray-700 dark:text-gray-300">New
                                                                Episode</span>
                                                            <span
                                                                class="text-sm text-gray-900 dark:text-gray-100 ml-2"><x-heroicon-s-check
                                                                    class="w-4 h-4 inline-block" /></span>
                                                        </div>
                                                        <div x-show="programme.desc" class="space-y-2">
                                                            <span
                                                                class="text-sm font-medium text-gray-700 dark:text-gray-300">Description</span>
                                                            <p class="text-sm text-gray-600 dark:text-gray-400"
                                                                x-text="programme.desc"></p>
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

            <!-- Footer Bar -->
            <div
                x-show="totalChannelCount > 0"
                class="absolute right-0 bottom-0 left-0 z-30 flex h-12 items-center justify-between border-t border-gray-200 bg-gray-50 px-3 dark:border-gray-600 dark:bg-gray-800"
            >
                <!-- Left: Mode toggle + per-page (pages mode only) -->
                <div class="flex items-center gap-2">
                    <button
                        @click="togglePaginationMode()"
                        class="p-1 text-gray-500 transition-colors hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                        x-tooltip="isScrollMode ? '{{ __('Switch to pages') }}' : '{{ __('Switch to infinite scroll') }}'"
                        :title="isScrollMode ? '{{ __('Switch to pages') }}' : '{{ __('Switch to infinite scroll') }}'"
                    >
                        <template x-if="isScrollMode">
                            <x-heroicon-m-numbered-list class="h-4 w-4" />
                        </template>
                        <template x-if="! isScrollMode">
                            <x-heroicon-m-bars-arrow-down class="h-4 w-4" />
                        </template>
                    </button>
                    <template x-if="! isScrollMode">
                        <div class="flex items-center gap-2">
                            <label class="hidden text-xs text-gray-500 sm:inline dark:text-gray-400">Per page</label>
                            <select
                                @change="changePerPage($event.target.value)"
                                :value="perPage"
                                class="rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-700 focus:ring-1 focus:ring-indigo-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                            >
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </template>
                </div>

                <!-- Center: Page navigation (pages mode) / channel count (scroll mode) -->
                <template x-if="! isScrollMode">
                    <div class="flex items-center gap-1 sm:gap-2">
                        <button
                            @click="previousPage()"
                            :disabled="currentPage <= 1"
                            :class="currentPage <= 1
                                ? 'text-gray-300 dark:text-gray-600 cursor-not-allowed'
                                : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100'"
                            class="rounded p-1 transition-colors"
                        >
                            <x-heroicon-m-chevron-left class="h-4 w-4" />
                        </button>

                        <div class="flex items-center gap-1">
                            <span class="hidden text-xs text-gray-500 sm:inline dark:text-gray-400">Page</span>
                            <input
                                type="text"
                                x-model="pageInput"
                                :placeholder="currentPage"
                                @blur="goToPage(pageInput || currentPage)"
                                class="w-10 rounded border border-gray-300 bg-white px-1 py-1 text-center text-xs text-gray-700 focus:ring-1 focus:ring-indigo-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                            />
                            <span class="text-xs text-gray-500 dark:text-gray-400">of</span>
                            <span
                                class="text-xs font-medium text-gray-700 dark:text-gray-300"
                                x-text="totalPages"
                            ></span>
                        </div>

                        <button
                            @click="nextPage()"
                            :disabled="currentPage >= totalPages"
                            :class="currentPage >= totalPages
                                ? 'text-gray-300 dark:text-gray-600 cursor-not-allowed'
                                : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100'"
                            class="rounded p-1 transition-colors"
                        >
                            <x-heroicon-m-chevron-right class="h-4 w-4" />
                        </button>
                    </div>
                </template>
                <template x-if="isScrollMode">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="channelOrder.length"></span> of <span x-text="totalChannelCount"></span>
                        <span class="hidden sm:inline">channels loaded</span><span class="sm:hidden">loaded</span>
                    </div>
                </template>

                <!-- Right: Total count -->
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    <span x-text="totalChannelCount"></span> <span class="hidden sm:inline">channels</span
                    ><span class="sm:hidden">ch.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Filament Actions Modals -->
    <x-filament-actions::modals />
</div>
</div>
