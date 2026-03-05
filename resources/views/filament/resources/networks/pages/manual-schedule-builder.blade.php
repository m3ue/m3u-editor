<x-filament-panels::page>
    <div
        x-data="scheduleBuilder({
            networkId: {{ $network->id }},
            scheduleWindowDays: {{ $scheduleWindowDays }},
            recurrenceMode: '{{ $recurrenceMode }}',
            gapSeconds: {{ $gapSeconds }},
        })"
        x-cloak
        class="schedule-builder"
    >
        {{-- Day Navigation --}}
        <div class="flex items-center justify-between mb-4 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-3">
            <div class="flex items-center gap-2">
                <button
                    @click="previousDay()"
                    class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                    :disabled="!canGoPrevious()"
                    :class="{ 'opacity-50 cursor-not-allowed': !canGoPrevious() }"
                >
                    <x-heroicon-s-chevron-left class="w-4 h-4" />
                </button>

                <button
                    @click="goToToday()"
                    class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/20 hover:bg-primary-100 dark:hover:bg-primary-900/40 transition"
                >
                    Today
                </button>

                <button
                    @click="nextDay()"
                    class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                    :disabled="!canGoNext()"
                    :class="{ 'opacity-50 cursor-not-allowed': !canGoNext() }"
                >
                    <x-heroicon-s-chevron-right class="w-4 h-4" />
                </button>

                <span class="ml-2 text-lg font-semibold text-gray-900 dark:text-white" x-text="currentDateDisplay"></span>
                <span class="text-sm text-gray-500 dark:text-gray-400" x-text="'(' + currentDayOfWeek + ')'"></span>
            </div>

            <div class="flex items-center gap-2">
                {{-- Day actions --}}
                <button
                    @click="clearCurrentDay()"
                    class="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-danger-600 dark:text-danger-400 bg-danger-50 dark:bg-danger-900/20 hover:bg-danger-100 dark:hover:bg-danger-900/40 transition"
                >
                    <x-heroicon-o-trash class="w-4 h-4" />
                    Clear Day
                </button>

                <button
                    @click="openCopyModal()"
                    class="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                >
                    <x-heroicon-o-document-duplicate class="w-4 h-4" />
                    Copy Day
                </button>

                <template x-if="recurrenceMode === 'weekly'">
                    <button
                        @click="applyWeeklyTemplate()"
                        class="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-success-600 dark:text-success-400 bg-success-50 dark:bg-success-900/20 hover:bg-success-100 dark:hover:bg-success-900/40 transition"
                    >
                        <x-heroicon-o-arrow-path class="w-4 h-4" />
                        Apply Weekly Template
                    </button>
                </template>
            </div>
        </div>

        {{-- Now-Playing Status Indicator --}}
        <div class="mb-4 flex items-center gap-2 px-3 py-2 rounded-lg border text-sm"
             :class="{
                 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-200': nowPlaying?.status === 'playing',
                 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200': nowPlaying?.status === 'gap',
                 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200': nowPlaying?.status === 'empty',
                 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400': !nowPlaying,
             }"
        >
            <template x-if="nowPlaying?.status === 'playing'">
                <span class="flex items-center gap-2">
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
                    </span>
                    Now Playing: <strong x-text="nowPlaying.title"></strong>
                </span>
            </template>
            <template x-if="nowPlaying?.status === 'gap'">
                <span class="flex items-center gap-2">
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-500"></span>
                    </span>
                    No programme right now &mdash; Next: <strong x-text="nowPlaying.next_title"></strong>
                </span>
            </template>
            <template x-if="nowPlaying?.status === 'empty'">
                <span class="flex items-center gap-2">
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                    </span>
                    No programmes scheduled
                </span>
            </template>
            <template x-if="!nowPlaying">
                <span class="flex items-center gap-2">Loading status...</span>
            </template>
        </div>

        {{-- Main Layout: Grid + Media Pool --}}
        <div class="flex gap-4" style="min-height: 70vh;">
            {{-- Time Grid --}}
            <div class="flex-1 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                {{-- Click-to-assign banner --}}
                <div
                    x-show="selectedMediaItem"
                    x-cloak
                    class="px-3 py-2 bg-primary-50 dark:bg-primary-900/30 border-b border-primary-200 dark:border-primary-800 flex items-center justify-between"
                >
                    <span class="text-xs text-primary-700 dark:text-primary-300">
                        Click a time slot to place: <strong x-text="selectedMediaItem?.title"></strong>
                    </span>
                    <button
                        @click="selectedMediaItem = null"
                        class="text-xs text-primary-600 dark:text-primary-400 hover:underline"
                    >Cancel</button>
                </div>

                {{-- Scrollable grid container --}}
                <div
                    x-ref="timeGrid"
                    class="overflow-y-auto"
                    style="max-height: 70vh;"
                    :class="{ 'cursor-crosshair': selectedMediaItem }"
                    @dragover.prevent="handleGridDragOver($event)"
                    @dragleave="handleGridDragLeave($event)"
                    @drop.prevent="handleGridDrop($event)"
                    @click="handleGridClick($event)"
                >
                    <div class="relative">
                        {{-- Time slots --}}
                        <template x-for="slot in timeSlots" :key="slot.time">
                            <div
                                class="flex border-b border-gray-100 dark:border-gray-700/50 relative group slot-row"
                                :class="{
                                    'bg-primary-50/30 dark:bg-primary-900/10': slot.isHour && dropTarget !== slot.time,
                                    'bg-gray-50/50 dark:bg-gray-800': !slot.isHour && dropTarget !== slot.time,
                                    'bg-primary-100 dark:bg-primary-900/30 ring-2 ring-inset ring-primary-400 dark:ring-primary-500': dropTarget === slot.time,
                                }"
                                :data-slot-time="slot.time"
                                style="min-height: 48px;"
                            >
                                {{-- Time Label --}}
                                <div class="w-16 shrink-0 flex items-start justify-end pr-2 pt-1.5 select-none"
                                     :class="slot.isHour ? 'text-gray-700 dark:text-gray-300 font-medium text-sm' : 'text-gray-400 dark:text-gray-500 text-xs'">
                                    <span x-text="slot.label"></span>
                                </div>

                                {{-- Slot Content Area --}}
                                <div class="flex-1 relative px-2 py-1 min-h-[48px]">
                                    {{-- Programme Block — pointer-events:none is applied via
                                         getProgrammeStyle() so drag/click events pass through
                                         to the grid container beneath. --}}
                                    <template x-for="prog in getProgrammesAtSlot(slot.time)" :key="prog.id">
                                        <div
                                            class="absolute left-2 right-2 rounded-lg shadow-sm border overflow-hidden transition-all"
                                            :class="getTypeColor(prog.contentable_type)"
                                            :style="getProgrammeStyle(prog, slot.time)"
                                        >
                                            <div class="flex items-start gap-2 p-2 h-full">
                                                <template x-if="prog.image">
                                                    <img :src="prog.image" class="w-8 h-8 rounded object-cover shrink-0" loading="lazy" />
                                                </template>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-xs font-medium truncate" x-text="prog.title"></p>
                                                    <p class="text-xs opacity-70" x-text="formatDuration(prog.duration_seconds)"></p>
                                                </div>
                                                {{-- Remove button — re-enable pointer-events so it's clickable --}}
                                                <button
                                                    @click.stop="handleRemoveClick($event, prog.id)"
                                                    class="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity p-0.5 rounded hover:bg-black/10"
                                                    style="pointer-events: auto;"
                                                >
                                                    <x-heroicon-o-x-mark class="w-3.5 h-3.5" />
                                                </button>
                                            </div>
                                        </div>
                                    </template>

                                    {{-- Empty slot indicator --}}
                                    <template x-if="getProgrammesAtSlot(slot.time).length === 0">
                                        <div class="w-full h-full flex items-center opacity-0 group-hover:opacity-100 transition-opacity">
                                            <span class="text-xs text-gray-400 dark:text-gray-500 italic">Drop media here or click to assign</span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Media Pool Sidebar --}}
            <div class="w-72 shrink-0 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 flex flex-col overflow-hidden">
                {{-- Pool Header --}}
                <div class="p-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Media Pool</h3>

                    {{-- Toggle: Network Content / All Media --}}
                    <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400 cursor-pointer">
                        <input type="checkbox" x-model="showAllMedia" @change="loadMediaPool()" class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500" />
                        Show all media
                    </label>

                    {{-- Search --}}
                    <input
                        type="text"
                        x-model="mediaSearch"
                        placeholder="Search media..."
                        class="mt-2 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm px-3 py-1.5 focus:ring-primary-500 focus:border-primary-500"
                    />
                </div>

                {{-- Pool Items --}}
                <div class="flex-1 overflow-y-auto p-2 space-y-1">
                    <template x-if="loadingPool">
                        <div class="flex items-center justify-center py-8">
                            <x-filament::loading-indicator class="w-6 h-6" />
                        </div>
                    </template>

                    <template x-if="!loadingPool && filteredMediaPool.length === 0">
                        <p class="text-center text-xs text-gray-400 dark:text-gray-500 py-8">No media available</p>
                    </template>

                    <template x-for="item in filteredMediaPool" :key="item.contentable_type + '-' + item.contentable_id">
                        <div
                            class="flex items-center gap-2 p-2 rounded-lg border border-gray-200 dark:border-gray-700 cursor-grab hover:bg-gray-50 dark:hover:bg-gray-700/50 transition"
                            :class="{ 'ring-2 ring-primary-400': selectedMediaItem && selectedMediaItem.contentable_id === item.contentable_id && selectedMediaItem.contentable_type === item.contentable_type }"
                            draggable="true"
                            @dragstart="handleMediaDragStart($event, item)"
                            @click="selectMediaItem(item)"
                        >
                            <template x-if="item.image">
                                <img :src="item.image" class="w-10 h-10 rounded object-cover shrink-0" loading="lazy" />
                            </template>
                            <template x-if="!item.image">
                                <div class="w-10 h-10 rounded bg-gray-200 dark:bg-gray-600 flex items-center justify-center shrink-0">
                                    <x-heroicon-o-film class="w-5 h-5 text-gray-400" />
                                </div>
                            </template>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-medium text-gray-900 dark:text-white truncate" x-text="item.title"></p>
                                <div class="flex items-center gap-1">
                                    <span
                                        class="inline-block text-[10px] font-medium px-1.5 py-0.5 rounded"
                                        :class="item.type === 'episode' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'"
                                        x-text="item.type === 'episode' ? 'Episode' : 'Movie'"
                                    ></span>
                                    <span class="text-[10px] text-gray-500 dark:text-gray-400" x-text="item.duration_display"></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Copy Day Modal --}}
        <div
            x-show="showCopyModal"
            x-cloak
            @keydown.escape.window="showCopyModal = false"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div @click.outside="showCopyModal = false" class="bg-white dark:bg-gray-800 rounded-xl shadow-xl p-6 w-full max-w-sm border border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Copy Day Schedule</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                    Copy <strong x-text="currentDateDisplay"></strong>'s schedule to:
                </p>
                <select
                    x-model="copyTargetDate"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm mb-4"
                >
                    <template x-for="date in availableDates" :key="date.value">
                        <option :value="date.value" :disabled="date.value === currentDate" x-text="date.label"></option>
                    </template>
                </select>
                <div class="flex justify-end gap-2">
                    <button @click="showCopyModal = false" class="rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        Cancel
                    </button>
                    <button @click="copyDay()" class="rounded-lg px-4 py-2 text-sm text-white bg-primary-600 hover:bg-primary-700 transition">
                        Copy
                    </button>
                </div>
            </div>
        </div>

        {{-- Loading Overlay --}}
        <div x-show="loading" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/20">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl p-6 flex items-center gap-3">
                <x-filament::loading-indicator class="w-6 h-6" />
                <span class="text-sm text-gray-700 dark:text-gray-300">Loading schedule...</span>
            </div>
        </div>
    </div>
</x-filament-panels::page>
