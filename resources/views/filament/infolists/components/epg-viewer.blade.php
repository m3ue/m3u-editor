@php
    $record = $getRecord();
    $class = class_basename($record);
    $route = $class === 'Epg' 
        ? route('api.epg.data', ['uuid' => $record?->uuid]) 
        : route('api.epg.playlist.data', ['uuid' => $record?->uuid]) ;
@endphp

<div 
    x-data="epgViewer({ 
        apiUrl: '{{ $route }}' 
    })"
    x-init="init(); loadEpgData()"
    class="w-full"
>
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
        <div x-show="!loading && !error" class="space-y-4">
            <!-- Date Navigation -->
            <div class="flex items-center justify-between bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                <div class="flex items-center space-x-4">
                    <button 
                        @click="previousDay()"
                        class="flex items-center px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                    >
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        Previous
                    </button>
                    
                    <div class="flex flex-col">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100" x-text="epgData?.epg?.name || 'EPG Viewer'"></h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="formatDate(currentDate)"></p>
                    </div>
                    
                    <button 
                        @click="nextDay()"
                        class="flex items-center px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                    >
                        Next
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>

                <div class="flex items-center space-x-2">
                    <button 
                        @click="goToToday()"
                        class="px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                    >
                        Today
                    </button>
                    <button 
                        @click="scrollToCurrentTime()"
                        x-show="isToday()"
                        class="px-3 py-2 text-sm font-medium text-blue-700 dark:text-blue-300 bg-blue-100 dark:bg-blue-900/30 border border-blue-300 dark:border-blue-700 rounded-md hover:bg-blue-200 dark:hover:bg-blue-900/50 transition-colors"
                        title="Scroll to current time"
                    >
                        📍 Now
                    </button>
                    {{-- <input 
                        type="date" 
                        x-model="currentDate"
                        @change="loadEpgData()"
                        class="px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    > --}}
                </div>
            </div>

            <!-- EPG Grid Container -->
            <div class="bg-white dark:bg-gray-800 relative border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden" style="height: 600px;">
                 <!-- Loading More Overlay -->
                <div 
                    x-show="loadingMore" 
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="absolute top-0 left-0 right-0 z-50 bg-blue-50 dark:bg-blue-900/20 border-b border-blue-200 dark:border-blue-800 px-4 py-2"
                >
                    <div class="flex items-center justify-center space-x-2">
                        <svg class="animate-spin h-4 w-4 text-blue-500 dark:text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-sm text-blue-700 dark:text-blue-300">Loading more channels...</span>
                    </div>
                </div>
                <!-- Time Header -->
                <div class="sticky top-0 z-20 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600">
                    <div class="flex">
                        <!-- Channel Column Header -->
                        <div class="w-48 px-4 py-3 border-r border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-800">
                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Channels</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400 ml-2" x-text="`(${Object.keys(epgData?.channels || {}).length})`"></span>
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
                                        <div class="w-25 px-2 py-3 border-r border-gray-200 dark:border-gray-600 text-center bg-gray-100 dark:bg-gray-800" style="width: 100px;">
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300" x-text="formatTime(hour)"></span>
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
                <div class="flex h-full overflow-hidden" x-data="{ scrollContainer: null }" x-init="scrollContainer = $el.querySelector('.timeline-scroll')">
                    <!-- Channel List (Fixed vertically, scrolls with timeline) -->
                    <div class="w-48 border-r border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 overflow-hidden">
                        <div 
                            class="overflow-y-auto h-full"
                            @scroll="if (scrollContainer) scrollContainer.scrollTop = $el.scrollTop"
                            x-ref="channelScroll"
                        >
                            <template x-for="(channel, channelId) in epgData?.channels || {}" :key="channelId">
                                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-600 flex items-center space-x-3 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" style="height: 60px;">
                                    <div class="flex-shrink-0">
                                        <img 
                                            :src="channel.icon || '/placeholder.png'" 
                                            :alt="channel.display_name"
                                            class="w-8 h-8 rounded object-cover"
                                            onerror="this.src='/placeholder.png'"
                                        >
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="channel.display_name"></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="channelId"></p>
                                    </div>
                                </div>
                            </template>

                            <!-- Loading indicator at bottom when more data is being loaded -->
                            <div x-show="hasMore && !loadingMore" class="px-4 py-3 text-center">
                                <div class="text-xs text-gray-500 dark:text-gray-400">Scroll down for more channels...</div>
                            </div>
                        </div>
                    </div>

                    <!-- Programme Timeline (Scrollable) -->
                    <div 
                        class="flex-1 overflow-auto relative timeline-scroll"
                        @scroll="
                            $refs.channelScroll.scrollTop = $el.scrollTop;
                            document.querySelector('.time-header-scroll').scrollLeft = $el.scrollLeft;
                            handleScroll($event);
                        "
                    >
                        <div class="relative" style="width: 2400px;"> <!-- 24 hours * 100px per hour -->
                            <!-- Current time indicator for programme area -->
                            <div 
                                x-show="isToday() && currentTimePosition >= 0"
                                class="absolute top-0 bottom-0 w-0.5 bg-red-500 z-30 pointer-events-none"
                                :style="`left: ${currentTimePosition}px;`"
                            ></div>
                            
                            <template x-for="(channel, channelId) in epgData?.channels || {}" :key="channelId">
                                <div class="relative border-b border-gray-100 dark:border-gray-600" style="height: 60px;">
                                    <!-- Time grid background -->
                                    <div class="absolute inset-0 flex">
                                        <template x-for="hour in timeSlots" :key="`${channelId}-${hour}`">
                                            <div class="w-25 border-r border-gray-200 dark:border-gray-600" style="width: 100px;"></div>
                                        </template>
                                    </div>
                                    
                                    <!-- Programme blocks -->
                                    <div class="absolute inset-0">
                                        <template x-for="programme in getChannelProgrammes(channelId)" :key="`${channelId}-${programme.start}-${programme.title}`">
                                            <div 
                                                class="absolute top-1 bottom-1 rounded shadow-sm cursor-pointer group transition-all duration-200"
                                                :class="getProgrammeColorClass(programme)"
                                                :style="getProgrammeStyle(programme)"
                                                @click="selectProgramme(programme)"
                                                x-data="{ showTooltip: false }"
                                                @mouseenter="showTooltip = true"
                                                @mouseleave="showTooltip = false"
                                            >
                                                <div class="p-2 h-full overflow-hidden flex flex-col justify-center">
                                                    <div class="text-xs font-medium text-gray-900 dark:text-gray-100 truncate leading-tight" x-text="programme.title"></div>
                                                    <div class="text-xs text-gray-600 dark:text-gray-300 truncate" x-text="formatProgrammeTime(programme)"></div>
                                                </div>
                                                
                                                <!-- Enhanced Tooltip -->
                                                <div 
                                                    x-show="showTooltip"
                                                    x-transition:enter="transition ease-out duration-200"
                                                    x-transition:enter-start="opacity-0 transform scale-95"
                                                    x-transition:enter-end="opacity-100 transform scale-100"
                                                    x-transition:leave="transition ease-in duration-150"
                                                    x-transition:leave-start="opacity-100 transform scale-100"
                                                    x-transition:leave-end="opacity-0 transform scale-95"
                                                    class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 px-3 py-2 bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-xs rounded-lg z-30 pointer-events-none max-w-xs shadow-lg"
                                                    style="min-width: 200px;"
                                                >
                                                    <div class="font-medium" x-text="programme.title"></div>
                                                    <div class="text-gray-300 dark:text-gray-600 mt-1" x-text="formatProgrammeTime(programme)"></div>
                                                    <div x-show="programme.desc" class="mt-1 text-gray-200 dark:text-gray-700" x-text="programme.desc"></div>
                                                    <div x-show="programme.category" class="mt-1 text-blue-300 dark:text-blue-600 text-xs" x-text="'Category: ' + programme.category"></div>
                                                    <!-- Arrow -->
                                                    <div class="absolute top-full left-1/2 transform -translate-x-1/2 w-0 h-0 border-l-4 border-r-4 border-t-4 border-transparent border-t-gray-900 dark:border-t-gray-100"></div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Programme Details Modal -->
            <div x-show="selectedProgramme" x-transition.opacity class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
                <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity" @click="selectedProgramme = null"></div>
                    
                    <div class="inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white dark:bg-gray-800 shadow-xl rounded-lg">
                        <div class="flex items-start justify-between">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100" x-text="selectedProgramme?.title"></h3>
                            <button @click="selectedProgramme = null" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                        
                        <div class="mt-4 space-y-3">
                            <div>
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Time:</span>
                                <span class="text-sm text-gray-900 dark:text-gray-100 ml-2" x-text="selectedProgramme ? formatProgrammeTime(selectedProgramme) : ''"></span>
                            </div>
                            
                            <div x-show="selectedProgramme?.category">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Category:</span>
                                <span class="text-sm text-gray-900 dark:text-gray-100 ml-2" x-text="selectedProgramme?.category"></span>
                            </div>
                            
                            <div x-show="selectedProgramme?.desc" class="space-y-2">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Description:</span>
                                <p class="text-sm text-gray-600 dark:text-gray-400" x-text="selectedProgramme?.desc"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
</div>