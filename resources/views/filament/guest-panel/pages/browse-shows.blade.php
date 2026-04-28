<x-filament-panels::page>
    {{-- Page description --}}
    <div class="rounded-lg border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 px-4 py-3 mb-6 flex items-start gap-3">
        <svg class="w-5 h-5 text-primary-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" />
        </svg>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Search your EPG guide to find shows and movies, then create recording rules to capture them automatically. Schedule a single airing or set up a series rule to record every episode as it airs.') }}
        </p>
    </div>

    {{-- Filter Form --}}
    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4 mb-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {{-- Keyword --}}
            <div class="flex flex-col gap-1">
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">{{ __('Title Keyword') }}</span>
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="keyword"
                        placeholder="{{ __('e.g. Breaking Bad') }}" />
                </x-filament::input.wrapper>
            </div>

            {{-- Category --}}
            <div class="flex flex-col gap-1">
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">{{ __('Category') }}</span>
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="category"
                        placeholder="{{ __('e.g. Drama') }}" />
                </x-filament::input.wrapper>
            </div>

            {{-- Description Keyword --}}
            <div class="flex flex-col gap-1">
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">{{ __('Description Keyword') }}</span>
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="description_keyword"
                        placeholder="{{ __('e.g. detective') }}" />
                </x-filament::input.wrapper>
            </div>

            {{-- Group (searchable) --}}
            <div class="flex flex-col gap-1"
                 x-data="{
                     open: false,
                     search: '',
                     allOptions: @js($this->groupOptions),
                     get filtered() {
                         if (!this.search) return this.allOptions;
                         const q = this.search.toLowerCase();
                         return Object.fromEntries(
                             Object.entries(this.allOptions).filter(([id, label]) => label.toLowerCase().includes(q))
                         );
                     }
                 }"
                 x-effect="if (!$wire.group_id) search = ''">
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">{{ __('Group') }}</span>
                </label>
                <div class="relative">
                    <input type="text"
                           x-model="search"
                           @focus="open = true"
                           @keydown.escape="open = false"
                           :placeholder="!$wire.group_id ? '{{ __('— Any —') }}' : ''"
                           class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 placeholder-gray-400 dark:placeholder-gray-500 py-2 pl-3" />
                    <div x-show="open && Object.keys(filtered).length > 0"
                         x-transition
                         @click.stop
                         @keydown.escape="open = false"
                         class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-white/10 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                        <button type="button"
                                @click="search = ''; $wire.group_id = ''; open = false"
                                class="w-full text-left px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-white/10 transition border-b border-gray-100 dark:border-white/5"
                                :class="!$wire.group_id ? 'text-primary-600 dark:text-primary-400 font-medium' : 'text-gray-600 dark:text-gray-300'">
                            {{ __('— Any —') }}
                        </button>
                        <template x-for="[id, label] in Object.entries(filtered)" :key="id">
                            <button type="button"
                                    @click="search = label; $wire.group_id = parseInt(id); open = false"
                                    class="w-full text-left px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-white/10 transition"
                                    :class="$wire.group_id == id ? 'text-primary-600 dark:text-primary-400 font-medium' : 'text-gray-700 dark:text-gray-200'"
                                    x-text="label"></button>
                        </template>
                        <div x-show="Object.keys(filtered).length === 0" class="px-3 py-2 text-sm text-gray-400 dark:text-gray-500">
                            {{ __('No matches') }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- Channel (searchable) --}}
            <div class="flex flex-col gap-1"
                 x-data="{
                     open: false,
                     search: '',
                     allOptions: @js($this->channelOptions),
                     get filtered() {
                         if (!this.search) return this.allOptions;
                         const q = this.search.toLowerCase();
                         return Object.fromEntries(
                             Object.entries(this.allOptions).filter(([id, label]) => label.toLowerCase().includes(q))
                         );
                     }
                 }"
                 x-effect="if (!$wire.channel_id) search = ''">
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">{{ __('Channel') }}</span>
                </label>
                <div class="relative">
                    <input type="text"
                           x-model="search"
                           @focus="open = true"
                           @keydown.escape="open = false"
                           :placeholder="!$wire.channel_id ? '{{ __('— Any —') }}' : ''"
                           class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 placeholder-gray-400 dark:placeholder-gray-500 py-2 pl-3" />
                    <div x-show="open && Object.keys(filtered).length > 0"
                         x-transition
                         @click.stop
                         @keydown.escape="open = false"
                         class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-white/10 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                        <button type="button"
                                @click="search = ''; $wire.channel_id = ''; open = false"
                                class="w-full text-left px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-white/10 transition border-b border-gray-100 dark:border-white/5"
                                :class="!$wire.channel_id ? 'text-primary-600 dark:text-primary-400 font-medium' : 'text-gray-600 dark:text-gray-300'">
                            {{ __('— Any —') }}
                        </button>
                        <template x-for="[id, label] in Object.entries(filtered)" :key="id">
                            <button type="button"
                                    @click="search = label; $wire.channel_id = parseInt(id); open = false"
                                    class="w-full text-left px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-white/10 transition"
                                    :class="$wire.channel_id == id ? 'text-primary-600 dark:text-primary-400 font-medium' : 'text-gray-700 dark:text-gray-200'"
                                    x-text="label"></button>
                        </template>
                        <div x-show="Object.keys(filtered).length === 0" class="px-3 py-2 text-sm text-gray-400 dark:text-gray-500">
                            {{ __('No matches') }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- Days --}}
            <div class="flex flex-col gap-1">
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">{{ __('Look-ahead Window') }}</span>
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model="days">
                        <option value="7">{{ __('7 days') }}</option>
                        <option value="14">{{ __('14 days') }}</option>
                        <option value="30">{{ __('30 days') }}</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </div>

        <div class="flex justify-end">
            <x-filament::button wire:click="search" icon="heroicon-m-magnifying-glass">
                {{ __('Search') }}
            </x-filament::button>
        </div>
    </div>

    {{-- Results --}}
    @if($searched)
        @if(empty($groupedShows))
            <div class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('No EPG programmes matched your search in the selected window.') }}
            </div>
        @else
            <div class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                {{ trans_choice(':count show found.|:count shows found.', count($groupedShows), ['count' => count($groupedShows)]) }}
            </div>

            {{-- Poster Card Grid --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                @foreach($groupedShows as $show)
                    <div class="relative flex flex-col rounded-xl overflow-visible bg-gray-100 dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow"
                         style="content-visibility: auto; contain-intrinsic-size: 350px 520px;"
                         x-data="{ menuOpen: false }">

                        {{-- Poster area --}}
                        <button type="button"
                                class="relative aspect-[2/3] rounded-t-xl overflow-hidden bg-gray-200 dark:bg-gray-800 cursor-pointer w-full text-left focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-500"
                                wire:click="openShowDetail({{ \Illuminate\Support\Js::from($show['title']) }})">

                            @if($show['poster_url'])
                                <img src="{{ $show['poster_url'] }}" alt="{{ $show['title'] }}"
                                     class="absolute inset-0 w-full h-full object-cover"
                                     loading="lazy" decoding="async" />
                            @elseif($postersLoaded && $show['epg_icon'])
                                <img src="{{ $show['epg_icon'] }}" alt="{{ $show['title'] }}"
                                     class="absolute inset-0 w-full h-full object-contain p-6"
                                     loading="lazy" decoding="async" />
                            @elseif($postersLoaded)
                                <div class="absolute inset-0 flex flex-col items-center justify-center text-gray-400 dark:text-gray-600 px-3 text-center gap-2">
                                    <svg class="w-10 h-10 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                              d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z" />
                                    </svg>
                                    <span class="text-xs leading-tight opacity-60">{{ $show['title'] }}</span>
                                </div>
                            @else
                                <div class="absolute inset-0 animate-pulse bg-gradient-to-b from-gray-300 to-gray-200 dark:from-gray-700 dark:to-gray-800"></div>
                            @endif

                            @if($show['has_series_rule'])
                                <span class="absolute top-2 right-2 px-1.5 py-0.5 text-xs font-semibold rounded bg-green-600 text-white shadow-sm">
                                    {{ __('Series') }}
                                </span>
                            @elseif($show['has_once_rule'])
                                <span class="absolute top-2 right-2 px-1.5 py-0.5 text-xs font-semibold rounded bg-blue-600 text-white shadow-sm">
                                    {{ __('Scheduled') }}
                                </span>
                            @endif

                            <div class="absolute top-2 left-2 flex flex-col gap-1">
                                @if($show['flags']['is_new'])
                                    <span class="px-1.5 py-0.5 text-xs font-medium rounded bg-emerald-500/90 text-white">{{ __('New') }}</span>
                                @endif
                                @if($show['flags']['premiere'])
                                    <span class="px-1.5 py-0.5 text-xs font-medium rounded bg-purple-500/90 text-white">{{ __('Premiere') }}</span>
                                @endif
                            </div>
                        </button>

                        {{-- Card footer --}}
                        <div class="p-3 flex items-start justify-between gap-2">
                            <button class="flex-1 min-w-0 text-left"
                                wire:click="openShowDetail({{ \Illuminate\Support\Js::from($show['title']) }})">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ $show['title'] }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    <span class="font-semibold text-gray-600 dark:text-gray-300">{{ __('Airing Next:') }}</span> {{ $show['next_air_date_human'] ?? '—' }}
                                </p>
                            </button>

                            {{-- Kebab menu --}}
                            <div class="relative flex-shrink-0">
                                <button @click.stop="menuOpen = !menuOpen"
                                        class="p-1.5 rounded-lg text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/30 transition">
                                    <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                    </svg>
                                </button>

                                <div x-show="menuOpen"
                                     @click.outside="menuOpen = false"
                                     x-transition:enter="transition ease-out duration-100"
                                     x-transition:enter-start="opacity-0 scale-95"
                                     x-transition:enter-end="opacity-100 scale-100"
                                     x-transition:leave="transition ease-in duration-75"
                                     x-transition:leave-start="opacity-100 scale-100"
                                     x-transition:leave-end="opacity-0 scale-95"
                                     class="absolute right-0 bottom-full mb-1 z-20 w-52 rounded-xl shadow-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-white/10 py-1 text-sm"
                                     style="display: none;">
                                    <button wire:click="openShowDetail({{ \Illuminate\Support\Js::from($show['title']) }})"
                                            @click="menuOpen = false"
                                            class="w-full text-left px-4 py-2.5 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/10 transition flex items-center gap-2">
                                        <svg class="w-4 h-4 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        {{ __('View Details') }}
                                    </button>
                                    <button wire:click="quickRecordNextAiring({{ \Illuminate\Support\Js::from($show['title']) }})"
                                            @click="menuOpen = false"
                                            class="w-full text-left px-4 py-2.5 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/10 transition flex items-center gap-2">
                                        <svg class="w-4 h-4 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        {{ __('Quick Record Next Airing') }}
                                    </button>
                                    <button wire:click="recordSeriesDefaults({{ \Illuminate\Support\Js::from($show['title']) }})"
                                            @click="menuOpen = false"
                                            class="w-full text-left px-4 py-2.5 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/10 transition flex items-center gap-2">
                                        <svg class="w-4 h-4 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                                        </svg>
                                        {{ __('Record Series (defaults)') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- Show Detail Slide-over --}}
    <div
        x-data="{ open: $wire.selectedShowTitle !== '' }"
        x-init="$watch('$wire.selectedShowTitle', v => { open = v !== '' })"
        @keydown.escape.window="if (open) $wire.call('closeShowDetail')"
        class="fixed inset-0 z-50 pointer-events-none"
        x-cloak
    >
        {{-- Backdrop --}}
        <div x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             @click="$wire.call('closeShowDetail')"
             class="absolute inset-0 bg-black/30 pointer-events-auto"
             style="display: none;"></div>

        {{-- Slide panel --}}
        <div x-show="open"
             x-transition:enter="transition ease-in-out duration-300 transform"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in-out duration-200 transform"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="absolute right-0 inset-y-0 w-full sm:max-w-xl bg-white dark:bg-gray-900 shadow-xl flex flex-col pointer-events-auto"
             style="display: none;">

            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-white/10 sticky top-0 bg-white dark:bg-gray-900 z-10">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white truncate pr-2">
                    {{ $selectedShowTitle }}
                </h2>
                <button wire:click="closeShowDetail"
                        class="ml-2 p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/10 transition flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Content --}}
            <div class="p-4 flex-1 overflow-y-auto">
                @php $selectedShow = collect($groupedShows)->firstWhere('title', $selectedShowTitle); @endphp
                @include('filament.pages.browse-show-detail', ['show' => $selectedShow ?? null, 'channelOptions' => $this->channelOptions])
            </div>
        </div>
    </div>
</x-filament-panels::page>
