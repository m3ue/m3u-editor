<x-filament-panels::page>
    @if ($network->schedule_type !== 'manual')
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-heroicon-o-information-circle class="w-5 h-5 text-warning-500 shrink-0 mt-0.5" />
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Schedule Builder is not active</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                        This network is currently using the <strong>{{ $network->schedule_type }}</strong> schedule type.
                        To use the Schedule Builder, change the <strong>Schedule Type</strong> to <strong>manual</strong> in
                        the
                        <a class="font-bold text-primary-600 dark:text-primary-400 hover:underline"
                            href="{{ url('networks/' . $network->id . '/edit?tab=schedule-settings%3A%3Adata%3A%3Atab') }}">Schedule
                            Settings
                        </a>.
                    </p>
                </div>
            </div>
        </x-filament::section>
    @else
        <div x-data="scheduleBuilder({
                                            networkId: {{ $network->id }},
                                            scheduleWindowDays: {{ $scheduleWindowDays }},
                                            recurrenceMode: '{{ $recurrenceMode }}',
                                            gapSeconds: {{ $gapSeconds }},
                                        })" x-cloak class="schedule-builder">
            {{-- Header bar: Day nav + Now playing + Actions --}}
            <div class="mb-4 space-y-3 lg:flex lg:flex-wrap lg:items-center lg:gap-3 lg:space-y-0">
                {{-- Day Navigation --}}
                <div
                    class="flex items-center gap-1.5 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-1.5">
                    <button @click="previousDay()"
                        class="inline-flex items-center justify-center rounded-lg w-8 h-8 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition"
                        :disabled="!canGoPrevious()" :class="{ 'opacity-30 cursor-not-allowed': !canGoPrevious() }">
                        <x-heroicon-s-chevron-left class="w-4 h-4" />
                    </button>
                    <button @click="goToToday()"
                        class="rounded-lg px-3 h-8 text-xs font-semibold text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/30 transition">
                        Today
                    </button>
                    <button @click="nextDay()"
                        class="inline-flex items-center justify-center rounded-lg w-8 h-8 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition"
                        :disabled="!canGoNext()" :class="{ 'opacity-30 cursor-not-allowed': !canGoNext() }">
                        <x-heroicon-s-chevron-right class="w-4 h-4" />
                    </button>
                </div>

                <div class="flex items-baseline gap-2">
                    <span class="text-lg font-bold text-gray-900 dark:text-white" x-text="currentDateDisplay"></span>
                    <span class="text-sm text-gray-400 dark:text-gray-500" x-text="currentDayOfWeek"></span>
                </div>

                {{-- Now-Playing pill --}}
                <div class="flex w-full items-center gap-1.5 text-xs rounded-full px-3 py-1.5 font-medium lg:ml-auto lg:w-auto" :class="{
                                                     'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300': nowPlaying?.status === 'playing',
                                                     'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300': nowPlaying?.status === 'gap',
                                                     'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300': nowPlaying?.status === 'empty',
                                                     'bg-gray-100 dark:bg-gray-800 text-gray-400': !nowPlaying,
                                                 }">
                    <template x-if="nowPlaying?.status === 'playing'">
                        <span class="flex items-center gap-1.5">
                            <span class="relative flex h-2 w-2">
                                <span
                                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                            </span>
                            <span>Now: <strong x-text="nowPlaying.title"></strong></span>
                        </span>
                    </template>
                    <template x-if="nowPlaying?.status === 'gap'">
                        <span class="flex items-center gap-1.5">
                            <span class="inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                            <span>Idle &mdash; Next: <strong x-text="nowPlaying.next_title"></strong></span>
                        </span>
                    </template>
                    <template x-if="nowPlaying?.status === 'empty'">
                        <span class="flex items-center gap-1.5">
                            <span class="inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                            <span>No programmes</span>
                        </span>
                    </template>
                    <template x-if="!nowPlaying">
                        <span>&hellip;</span>
                    </template>
                </div>

                {{-- Actions --}}
                <div class="flex flex-wrap items-center gap-1.5 lg:flex-nowrap">
                    <button @click="openCopyModal()"
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                        <x-heroicon-o-document-duplicate class="w-3.5 h-3.5" />
                        Copy Day
                    </button>
                    <template x-if="recurrenceMode === 'weekly'">
                        <button @click="applyWeeklyTemplate()"
                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/30 transition">
                            <x-heroicon-o-arrow-path class="w-3.5 h-3.5" />
                            Apply Template
                        </button>
                    </template>
                    <button @click="clearCurrentDay()"
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-danger-600 dark:text-danger-400 hover:bg-danger-50 dark:hover:bg-danger-900/20 transition">
                        <x-heroicon-o-trash class="w-3.5 h-3.5" />
                        Clear
                    </button>
                </div>
            </div>

            {{-- Schedule Summary Bar --}}
            <div x-show="programmes.length > 0"
                class="flex items-center gap-4 mb-3 px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800/50 border border-gray-200/60 dark:border-gray-700/40 text-xs text-gray-500 dark:text-gray-400">
                <span><strong x-text="programmes.length"></strong> programme(s)</span>
                <span>&middot;</span>
                <span>Total: <strong x-text="totalDuration"></strong></span>
                <template x-if="scheduleEndTime">
                    <span>&middot; Ends at <strong x-text="scheduleEndTime"></strong></span>
                </template>
            </div>

            {{-- Main Layout: Programme List + Sticky Media Pool --}}
            <div class="flex flex-col gap-5 items-stretch lg:flex-row lg:items-start">
                {{-- Programme List --}}
                <div class="order-2 flex-1 min-w-0 lg:order-1" @dragover="handleListDragOver($event)" @drop="handleListDrop($event)">
                    {{-- Empty state --}}
                    <template x-if="!loading && programmes.length === 0">
                        <div
                            class="flex flex-col items-center justify-center py-16 rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                            <x-heroicon-o-queue-list class="w-12 h-12 text-gray-300 dark:text-gray-600 mb-3" />
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">No programmes scheduled</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">Drag items from the media pool or click the
                                <strong>+</strong> button to add content
                            </p>
                        </div>
                    </template>

                    {{-- Programme Cards --}}
                    <div class="space-y-1">
                        <template x-for="(prog, index) in programmes" :key="prog.id">
                            <div>
                                {{-- Gap indicator between programmes --}}
                                <template x-if="index > 0 && gapBefore(index) !== null && gapBefore(index) !== 0">
                                    <div class="flex items-center gap-2 py-1 px-2">
                                        <div class="flex-1 border-t"
                                            :class="gapBefore(index) < 0 ? 'border-red-300 dark:border-red-700 border-dashed' : 'border-gray-200 dark:border-gray-700'">
                                        </div>
                                        <span class="text-[10px] font-medium shrink-0"
                                            :class="gapBefore(index) < 0 ? 'text-red-500 dark:text-red-400' : 'text-gray-400 dark:text-gray-500'"
                                            x-text="formatGap(gapBefore(index))"></span>
                                        <div class="flex-1 border-t"
                                            :class="gapBefore(index) < 0 ? 'border-red-300 dark:border-red-700 border-dashed' : 'border-gray-200 dark:border-gray-700'">
                                        </div>
                                    </div>
                                </template>

                                {{-- Programme Card --}}
                                <div class="relative">
                                    <div class="group/card flex items-stretch rounded-xl border shadow-sm transition-all hover:shadow-md"
                                        :class="[getTypeColor(prog.contentable_type), rowDragOverIndex === index ? 'ring-2 ring-primary-400 ring-offset-1 dark:ring-offset-gray-900' : '', rowDragIndex === index ? 'opacity-40' : '']"
                                        @dragover="handleRowDragOver($event, index)"
                                        @dragleave.self="rowDragOverIndex = null" @drop="handleRowDrop($event, index)">
                                        {{-- Drag handle | Up/Down arrows + position number --}}
                                        <div class="flex items-stretch shrink-0 border-r border-inherit">
                                            {{-- Drag handle --}}
                                            <div class="flex items-center justify-center w-7 border-r border-inherit cursor-grab active:cursor-grabbing"
                                                draggable="true" @dragstart.stop="handleRowDragStart($event, index)"
                                                @dragend.stop="handleRowDragEnd()" title="Drag to reorder">
                                                <x-heroicon-m-bars-3
                                                    class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover/card:text-gray-400 dark:group-hover/card:text-gray-500 transition" />
                                            </div>
                                            {{-- Up / number / Down --}}
                                            <div class="flex flex-col items-center justify-center w-8 gap-0.5 py-1">
                                                <button @click.stop="moveUp(index)"
                                                    class="p-0.5 rounded text-gray-300 dark:text-gray-600 hover:text-gray-500 dark:hover:text-gray-400 disabled:opacity-30 disabled:cursor-not-allowed transition"
                                                    :disabled="index === 0" title="Move up">
                                                    <x-heroicon-s-chevron-up class="w-3.5 h-3.5" />
                                                </button>
                                                <span
                                                    class="text-[10px] font-bold text-gray-400 dark:text-gray-500 select-none leading-none"
                                                    x-text="index + 1"></span>
                                                <button @click.stop="moveDown(index)"
                                                    class="p-0.5 rounded text-gray-300 dark:text-gray-600 hover:text-gray-500 dark:hover:text-gray-400 disabled:opacity-30 disabled:cursor-not-allowed transition"
                                                    :disabled="index >= programmes.length - 1" title="Move down">
                                                    <x-heroicon-s-chevron-down class="w-3.5 h-3.5" />
                                                </button>
                                            </div>
                                        </div>

                                        {{-- Poster image --}}
                                        <template x-if="prog.image">
                                            <div class="shrink-0 w-16 sm:w-20 relative overflow-hidden">
                                                <img :src="prog.image" class="absolute inset-0 w-full h-full object-cover"
                                                    loading="lazy" />
                                            </div>
                                        </template>

                                        {{-- Programme details --}}
                                        <div class="flex-1 min-w-0 px-3 py-2.5 flex flex-col justify-center">
                                            <div class="flex items-center gap-2">
                                                <p class="text-sm font-semibold text-gray-900 dark:text-white truncate leading-snug"
                                                    x-text="prog.title"></p>
                                                <span
                                                    class="shrink-0 text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full"
                                                    :class="getTypeBadge(prog.contentable_type)"
                                                    x-text="prog.contentable_type && prog.contentable_type.includes('Episode') ? 'EP' : 'MOV'"></span>
                                            </div>
                                            <div
                                                class="flex items-center gap-2 mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                                                <span class="font-medium" :class="getTypeAccent(prog.contentable_type)"
                                                    x-text="formatTimeRange(prog)"></span>
                                                <span>&middot;</span>
                                                <span x-text="formatDuration(prog.duration_seconds)"></span>
                                            </div>
                                        </div>

                                        {{-- Pin indicator / editor --}}
                                        <div class="flex items-center px-2 shrink-0">
                                            <template x-if="editingPinId === prog.id">
                                                <div class="flex items-center gap-1.5" @click.stop>
                                                    <input type="time" x-model="editingPinTime"
                                                        class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-2 py-1 w-28 focus:ring-primary-500 focus:border-primary-500"
                                                        @keydown.enter="savePin(prog.id)"
                                                        @keydown.escape="cancelEditPin()" />
                                                    <button @click.stop="savePin(prog.id)"
                                                        class="p-1 rounded-md text-green-600 hover:bg-green-50 dark:hover:bg-green-900/30"
                                                        title="Save pin">
                                                        <x-heroicon-o-check class="w-4 h-4" />
                                                    </button>
                                                    <button @click.stop="cancelEditPin()"
                                                        class="p-1 rounded-md text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"
                                                        title="Cancel">
                                                        <x-heroicon-o-x-mark class="w-4 h-4" />
                                                    </button>
                                                </div>
                                            </template>
                                            <template x-if="editingPinId !== prog.id">
                                                <div class="flex items-center">
                                                    <template x-if="prog.is_pinned">
                                                        <div class="flex items-center gap-1">
                                                            <span
                                                                class="text-[10px] font-medium text-amber-600 dark:text-amber-400"
                                                                x-text="prog.pinned_start_time"></span>
                                                            <button @click.stop="startEditPin(prog)"
                                                                class="p-1 rounded-md text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition"
                                                                title="Edit pinned time">
                                                                <x-heroicon-s-map-pin class="w-4 h-4" />
                                                            </button>
                                                            <button @click.stop="unpinTime(prog.id)"
                                                                class="p-1 rounded-md text-gray-300 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition opacity-100 sm:opacity-0 sm:group-hover/card:opacity-100"
                                                                title="Remove pin">
                                                                <x-heroicon-o-x-circle class="w-3.5 h-3.5" />
                                                            </button>
                                                        </div>
                                                    </template>
                                                    <template x-if="!prog.is_pinned">
                                                        <button @click.stop="startEditPin(prog)"
                                                            class="p-1 rounded-md text-gray-300 dark:text-gray-600 hover:text-amber-500 dark:hover:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition opacity-100 sm:opacity-0 sm:group-hover/card:opacity-100"
                                                            title="Pin to specific time">
                                                            <x-heroicon-o-map-pin class="w-4 h-4" />
                                                        </button>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>

                                        {{-- Actions --}}
                                        <div
                                            class="flex items-center px-2 shrink-0 opacity-100 sm:opacity-0 sm:group-hover/card:opacity-100 transition-opacity">
                                            <button @click.stop="confirmRemoveProgramme(prog.id)"
                                                class="p-1.5 rounded-md text-gray-400 dark:text-gray-500 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                                                title="Remove programme">
                                                <x-heroicon-o-trash class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Media Pool — sticky sidebar --}}
                <div
                    class="order-1 w-full shrink-0 lg:order-2 lg:w-72 lg:sticky lg:top-4 max-h-[60vh] lg:max-h-[calc(100vh-6rem)] flex flex-col bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    {{-- Pool Header --}}
                    <div class="p-3 border-b border-gray-200 dark:border-gray-700 space-y-2">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Media Pool</h3>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] text-gray-400 dark:text-gray-500"
                                    x-text="filteredMediaPool.length + ' items'"></span>
                                <button type="button" @click="toggleMediaPool()"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 lg:hidden"
                                    :aria-expanded="(!mediaPoolCollapsed).toString()" aria-controls="media-pool-body"
                                    title="Toggle media pool">
                                    <x-heroicon-s-chevron-down class="h-4 w-4 transition-transform"
                                        x-bind:class="mediaPoolCollapsed ? '' : 'rotate-180'" />
                                </button>
                            </div>
                        </div>

                        <input type="text" x-model.debounce.300ms="mediaSearch" placeholder="Search..."
                            class="w-full rounded-lg border-gray-200 dark:border-gray-600 dark:bg-gray-700/50 dark:text-white text-xs px-3 py-1.5 focus:ring-primary-500 focus:border-primary-500 placeholder-gray-400" />

                        <label class="flex items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400 cursor-pointer">
                            <input type="checkbox" x-model="showAllMedia" @change="loadMediaPool()"
                                class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500 w-3.5 h-3.5" />
                            Show all media
                        </label>
                    </div>

                    {{-- Pool Items --}}
                    <div id="media-pool-body" x-show="!mediaPoolCollapsed || window.innerWidth >= 1024" x-collapse
                        class="flex-1 overflow-y-auto p-2 space-y-1"
                        @scroll.passive="if ($el.scrollTop + $el.clientHeight >= $el.scrollHeight - 150) { loadMoreMedia() }">
                        <template x-if="loadingPool">
                            <div class="flex items-center justify-center py-8">
                                <x-filament::loading-indicator class="w-5 h-5" />
                            </div>
                        </template>

                        <template x-if="!loadingPool && filteredMediaPool.length === 0">
                            <p class="text-center text-[11px] text-gray-400 dark:text-gray-500 py-8">No media available</p>
                        </template>

                        <template x-for="item in filteredMediaPool"
                            :key="item.contentable_type + '-' + item.contentable_id">
                            <div class="group/item flex items-center gap-2.5 p-2 rounded-lg border border-gray-100 dark:border-gray-700/50 cursor-grab hover:border-gray-200 dark:hover:border-gray-600 hover:shadow-sm transition-all"
                                draggable="true" @dragstart="handlePoolDragStart($event, item)">
                                <template x-if="item.image">
                                    <img :src="item.image" class="w-10 h-14 rounded object-cover shrink-0" loading="lazy" />
                                </template>
                                <template x-if="!item.image">
                                    <div
                                        class="w-10 h-14 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center shrink-0">
                                        <x-heroicon-o-film class="w-4 h-4 text-gray-400" />
                                    </div>
                                </template>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-medium text-gray-900 dark:text-white truncate leading-tight"
                                        x-text="item.title"></p>
                                    <div class="flex items-center gap-1 mt-0.5">
                                        <span class="text-[10px] font-medium"
                                            :class="item.type === 'episode' ? 'text-blue-500 dark:text-blue-400' : 'text-purple-500 dark:text-purple-400'"
                                            x-text="item.type === 'episode' ? 'Episode' : 'Movie'"></span>
                                        <span class="text-[10px] text-gray-400">&middot;</span>
                                        <span class="text-[10px] text-gray-400 dark:text-gray-500"
                                            x-text="item.duration_display"></span>
                                    </div>
                                </div>
                                <button @click.stop="appendToEnd(item)"
                                    class="shrink-0 p-1.5 rounded-lg text-gray-300 dark:text-gray-600 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/30 opacity-100 sm:opacity-0 sm:group-hover/item:opacity-100 transition-all"
                                    title="Append to end of schedule">
                                    <x-heroicon-o-plus-circle class="w-4 h-4" />
                                </button>
                            </div>
                        </template>

                        <template x-if="loadingMorePool">
                            <div class="flex items-center justify-center py-4">
                                <x-filament::loading-indicator class="w-4 h-4" />
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Copy Day Modal --}}
            <x-filament::modal id="schedule-copy-day" width="sm">
                <x-slot name="heading">Copy Schedule</x-slot>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    Copy <strong class="text-gray-900 dark:text-white" x-text="currentDateDisplay"></strong> to:
                </p>
                <x-filament::input.wrapper class="w-full">
                    <x-filament::input.select x-model="copyTargetDate">
                        <template x-for="date in availableDates" :key="date.value">
                            <option :value="date.value" :disabled="date.value === currentDate" x-text="date.label"></option>
                        </template>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-slot name="footer">
                    <div class="flex justify-end gap-2 w-full">
                        <x-filament::button color="gray" @click="$dispatch('close-modal', { id: 'schedule-copy-day' })">
                            Cancel
                        </x-filament::button>
                        <x-filament::button color="primary" @click="copyDay()">
                            Copy
                        </x-filament::button>
                    </div>
                </x-slot>
            </x-filament::modal>

            {{-- Remove Programme Confirmation Modal --}}
            <x-filament::modal id="schedule-remove-programme" icon="heroicon-o-trash" icon-color="danger" width="sm">
                <x-slot name="heading">Remove Programme</x-slot>
                <x-slot name="description">
                    Remove this programme from the schedule?
                </x-slot>
                <x-slot name="footer">
                    <div class="flex justify-end gap-2 w-full">
                        <x-filament::button color="gray"
                            @click="$dispatch('close-modal', { id: 'schedule-remove-programme' })">
                            Cancel
                        </x-filament::button>
                        <x-filament::button color="danger" @click="removeProgramme()">
                            Remove
                        </x-filament::button>
                    </div>
                </x-slot>
            </x-filament::modal>

            {{-- Clear Day Confirmation Modal --}}
            <x-filament::modal id="schedule-clear-day" icon="heroicon-o-trash" icon-color="danger" width="sm">
                <x-slot name="heading">Clear Day</x-slot>
                <x-slot name="description">
                    Remove all programmes from <strong x-text="currentDateDisplay"></strong>? This cannot be undone.
                </x-slot>
                <x-slot name="footer">
                    <div class="flex justify-end gap-2 w-full">
                        <x-filament::button color="gray" @click="$dispatch('close-modal', { id: 'schedule-clear-day' })">
                            Cancel
                        </x-filament::button>
                        <x-filament::button color="danger" @click="confirmClearDay()">
                            Clear
                        </x-filament::button>
                    </div>
                </x-slot>
            </x-filament::modal>

            {{-- Apply Weekly Template Confirmation Modal --}}
            <x-filament::modal id="schedule-apply-template" icon="heroicon-o-calendar-days" icon-color="primary" width="sm">
                <x-slot name="heading">Apply Weekly Template</x-slot>
                <x-slot name="description">
                    Use the current week as a repeating template? Programmes beyond the first 7 days will be overwritten.
                </x-slot>
                <x-slot name="footer">
                    <div class="flex justify-end gap-2 w-full">
                        <x-filament::button color="gray"
                            @click="$dispatch('close-modal', { id: 'schedule-apply-template' })">
                            Cancel
                        </x-filament::button>
                        <x-filament::button color="primary" @click="confirmApplyTemplate()">
                            Apply Template
                        </x-filament::button>
                    </div>
                </x-slot>
            </x-filament::modal>

            {{-- Loading Overlay --}}
            <div x-show="loading" x-cloak x-transition.opacity
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/10">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl p-5 flex items-center gap-3">
                    <x-filament::loading-indicator class="w-5 h-5" />
                    <span class="text-sm text-gray-600 dark:text-gray-300">Loading...</span>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
