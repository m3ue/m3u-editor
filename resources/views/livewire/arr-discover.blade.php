<div>
    {{-- ── Load Failed ─────────────────────────────────────────────────── --}}
    @if ($loadFailed)
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <x-heroicon-o-exclamation-circle class="text-danger-400 mb-3 h-10 w-10" />
            <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Could not load discover') }}</h4>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('The request timed out or failed. Try again?') }}
            </p>
            <div class="mt-4">
                <x-filament::button
                    wire:click="reload"
                    wire:loading.attr="disabled"
                    wire:target="reload"
                    color="gray"
                    size="sm"
                    icon="heroicon-o-arrow-path"
                >
                    {{ __('Try Again') }}
                </x-filament::button>
            </div>
        </div>
    @else
        {{-- ── Genre Sections ──────────────────────────────────────────────── --}}
        @if (count($movieGenres) > 0 || count($tvGenres) > 0)
            <x-filament::section
                :collapsible="true"
                compact
                :id="'genres'"
                heading="{{ __('Browse by Genre') }}"
                icon="heroicon-o-tag"
                icon-color="primary"
                class="mb-4"
            >
                <div
                    x-data="{ tab: '{{ $browseGenreType ?? 'movie' }}' }"
                    x-init="
                        $watch(
                            () => $wire.browseGenreType,
                            (v) => {
                                if (v) tab = v;
                            },
                        )
                    "
                >
                    @if (count($movieGenres) > 0 && count($tvGenres) > 0)
                        <div class="mb-4 flex w-fit gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-800">
                            <button
                                @click="tab = 'movie'"
                                :class="tab === 'movie'
                                    ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm'
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                                class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-all"
                            >
                                <x-heroicon-o-film class="h-3.5 w-3.5" />
                                {{ __('Movies') }}
                            </button>
                            <button
                                @click="tab = 'tv'"
                                :class="tab === 'tv'
                                    ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm'
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                                class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-all"
                            >
                                <x-heroicon-o-tv class="h-3.5 w-3.5" />
                                {{ __('TV') }}
                            </button>
                        </div>
                    @endif

                    @if (count($movieGenres) > 0)
                        <div
                            x-show="tab === 'movie'"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0"
                            x-transition:enter-end="opacity-100"
                        >
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($movieGenres as $genre)
                                    @php $isActive = in_array($genre['id'], $browseGenreIds) && $browseGenreType === 'movie'; @endphp
                                    <button
                                        type="button"
                                        wire:click="toggleBrowseGenre({{ $genre['id'] }}, 'movie')"
                                        @class([
                                            'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium transition-colors border',
                                            'bg-primary-600 text-white border-primary-600' => $isActive,
                                            'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-transparent hover:bg-primary-50 dark:hover:bg-primary-900/30 hover:text-primary-700 dark:hover:text-primary-300 hover:border-primary-200 dark:hover:border-primary-800' => ! $isActive,
                                        ])
                                    >
                                        {{ $genre['name'] }}
                                        @if ($isActive)
                                            <x-heroicon-o-x-mark class="h-3 w-3 opacity-80" />
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (count($tvGenres) > 0)
                        <div
                            x-show="tab === 'tv'"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0"
                            x-transition:enter-end="opacity-100"
                        >
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($tvGenres as $genre)
                                    @php $isActive = in_array($genre['id'], $browseGenreIds) && $browseGenreType === 'tv'; @endphp
                                    <button
                                        type="button"
                                        wire:click="toggleBrowseGenre({{ $genre['id'] }}, 'tv')"
                                        @class([
                                            'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium transition-colors border',
                                            'bg-primary-600 text-white border-primary-600' => $isActive,
                                            'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-transparent hover:bg-primary-50 dark:hover:bg-primary-900/30 hover:text-primary-700 dark:hover:text-primary-300 hover:border-primary-200 dark:hover:border-primary-800' => ! $isActive,
                                        ])
                                    >
                                        {{ $genre['name'] }}
                                        @if ($isActive)
                                            <x-heroicon-o-x-mark class="h-3 w-3 opacity-80" />
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif

        {{-- ── Browse Results (genre selected) ──────────────────────────────── --}}
        @if (! empty($browseGenreIds))
            @php
                $browseName = collect($browseGenreType === 'tv' ? $tvGenres : $movieGenres)
                    ->whereIn('id', $browseGenreIds)
                    ->pluck('name')
                    ->join(' · ');
            @endphp

            @php
                $activeFilterCount =
                    (int) ($sortBy !== 'popularity') +
                    (int) ($yearFrom !== null || $yearTo !== null) +
                    (int) ($minRating > 0) +
                    (int) ($minVoteCount > 0) +
                    (int) ($minRuntime !== null || $maxRuntime !== null) +
                    (int) ($originalLanguage !== '') +
                    (int) ! empty($selectedProviders) +
                    (int) ! empty($tvStatuses);
            @endphp

            <div
                x-data="{ filtersOpen: @js($activeFilterCount > 0) }"
                class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900"
            >
                {{-- Header --}}
                <div class="flex items-center gap-2.5 border-b border-gray-200 bg-gray-50/80 px-4 py-3.5 dark:border-gray-700 dark:bg-white/5">
                    <x-filament::button wire:click="clearBrowse" color="gray" size="xs" icon="heroicon-o-arrow-left">
                        {{ __('All Genres') }}
                    </x-filament::button>
                    <span class="h-4 w-px bg-gray-200 dark:bg-gray-700"></span>
                    <h3 class="flex-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {{ $browseName }}
                        <span class="ml-1 font-normal text-gray-400 dark:text-gray-500">
                            — {{ $browseGenreType === 'tv' ? __('TV Series') : __('Movies') }}
                        </span>
                    </h3>
                    @if ($browseTotalPages > 1)
                        <span class="mr-1 text-xs font-medium text-gray-400 dark:text-gray-500">{{ __('Page :page of :total', ['page' => $browsePage, 'total' => $browseTotalPages]) }}</span>
                    @endif
                    <button
                        @click="filtersOpen = ! filtersOpen"
                        :class="filtersOpen
                            ? 'bg-primary-50 dark:bg-primary-900/30 border-primary-200 dark:border-primary-800 text-primary-700 dark:text-primary-300'
                            : 'bg-gray-100 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'"
                        class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors"
                    >
                        <x-heroicon-o-adjustments-horizontal class="h-3.5 w-3.5" />
                        {{ __('Filters') }}
                        @if ($activeFilterCount > 0)
                            <span class="bg-primary-600 inline-flex h-4 w-4 items-center justify-center rounded-full text-[10px] leading-none font-bold text-white">{{ $activeFilterCount }}</span>
                        @endif
                    </button>
                </div>

                {{-- Filter panel --}}
                <div x-show="filtersOpen" x-collapse class="border-b border-gray-200 dark:border-gray-700">
                    <div class="space-y-4 bg-gray-50/60 p-4 dark:bg-white/[0.02]">
                        {{-- Row 1: Sort + Language --}}
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Sort By') }}</label>
                                <select
                                    wire:model.live="sortBy"
                                    class="focus:border-primary-500 focus:ring-primary-500 w-full rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                >
                                    <option value="popularity">{{ __('Most Popular') }}</option>
                                    <option value="rating">{{ __('Highest Rated') }}</option>
                                    <option value="votes">{{ __('Most Voted') }}</option>
                                    <option value="newest">
                                        {{ $browseGenreType === 'tv' ? __('Latest Air Date') : __('Newest Release') }}
                                    </option>
                                    <option value="oldest">
                                        {{ $browseGenreType === 'tv' ? __('Earliest Air Date') : __('Oldest Release') }}
                                    </option>
                                    @if ($browseGenreType !== 'tv')
                                        <option value="revenue">{{ __('Box Office') }}</option>
                                    @endif
                                </select>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Original Language') }}</label>
                                <select
                                    wire:model.live="originalLanguage"
                                    class="focus:border-primary-500 focus:ring-primary-500 w-full rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                >
                                    <option value="">{{ __('Any Language') }}</option>
                                    <option value="en">{{ __('English') }}</option>
                                    <option value="fr">{{ __('French') }}</option>
                                    <option value="de">{{ __('German') }}</option>
                                    <option value="es">{{ __('Spanish') }}</option>
                                    <option value="pt">{{ __('Portuguese') }}</option>
                                    <option value="it">{{ __('Italian') }}</option>
                                    <option value="ja">{{ __('Japanese') }}</option>
                                    <option value="ko">{{ __('Korean') }}</option>
                                    <option value="zh">{{ __('Chinese') }}</option>
                                    <option value="ru">{{ __('Russian') }}</option>
                                    <option value="ar">{{ __('Arabic') }}</option>
                                    <option value="hi">{{ __('Hindi') }}</option>
                                    <option value="nl">{{ __('Dutch') }}</option>
                                    <option value="sv">{{ __('Swedish') }}</option>
                                    <option value="pl">{{ __('Polish') }}</option>
                                    <option value="tr">{{ __('Turkish') }}</option>
                                    <option value="th">{{ __('Thai') }}</option>
                                </select>
                            </div>
                        </div>

                        {{-- Row 2: Year + Rating --}}
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">
                                    {{ $browseGenreType === 'tv' ? __('First Air Year') : __('Release Year') }}
                                </label>
                                <div class="flex items-center gap-2">
                                    <input
                                        type="number"
                                        wire:model="yearFrom"
                                        min="1900"
                                        max="{{ date('Y') + 1 }}"
                                        placeholder="{{ __('From') }}"
                                        class="focus:border-primary-500 focus:ring-primary-500 w-full rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    />
                                    <span class="flex-shrink-0 text-xs text-gray-400">-</span>
                                    <input
                                        type="number"
                                        wire:model="yearTo"
                                        min="1900"
                                        max="{{ date('Y') + 1 }}"
                                        placeholder="{{ __('To') }}"
                                        class="focus:border-primary-500 focus:ring-primary-500 w-full rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    />
                                </div>
                            </div>
                            <div
                                x-data="{ r: @js($minRating) }"
                                x-init="
                                    $watch(
                                        () => $wire.minRating,
                                        (v) => (r = v ?? 0),
                                    )
                                "
                            >
                                <div class="mb-1.5 flex items-center justify-between">
                                    <label class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Min Rating') }}</label>
                                    <span
                                        x-text="r > 0 ? '★ ' + parseFloat(r).toFixed(1) + '+' : '{{ __('Any') }}'"
                                        :class="r > 0
                                            ? 'text-yellow-500 dark:text-yellow-400 font-semibold'
                                            : 'text-gray-400'"
                                        class="text-xs"
                                    ></span>
                                </div>
                                <input
                                    type="range"
                                    x-model="r"
                                    @change="$wire.minRating = parseFloat(r)"
                                    min="0"
                                    max="10"
                                    step="0.5"
                                    class="accent-primary-600 h-1.5 w-full cursor-pointer appearance-none rounded-lg bg-gray-200 dark:bg-gray-700"
                                />
                                <div class="mt-0.5 flex justify-between text-[10px] text-gray-400">
                                    <span>0</span><span>5</span><span>10</span>
                                </div>
                            </div>
                        </div>

                        {{-- Row 3: Runtime + Vote Count --}}
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Runtime (minutes)') }}</label>
                                <div class="flex items-center gap-2">
                                    <input
                                        type="number"
                                        wire:model="minRuntime"
                                        min="0"
                                        max="400"
                                        placeholder="{{ __('Min') }}"
                                        class="focus:border-primary-500 focus:ring-primary-500 w-full rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    />
                                    <span class="flex-shrink-0 text-xs text-gray-400">-</span>
                                    <input
                                        type="number"
                                        wire:model="maxRuntime"
                                        min="0"
                                        max="400"
                                        placeholder="{{ __('Max') }}"
                                        class="focus:border-primary-500 focus:ring-primary-500 w-full rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    />
                                </div>
                            </div>
                            <div>
                                <div class="mb-1.5 flex items-center justify-between">
                                    <label class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Min Vote Count') }}</label>
                                    @if ($minVoteCount > 0)
                                        <span class="text-primary-600 dark:text-primary-400 text-xs font-semibold">{{ number_format($minVoteCount) }}+</span>
                                    @else
                                        <span class="text-xs text-gray-400">{{ __('Any') }}</span>
                                    @endif
                                </div>
                                <input
                                    type="range"
                                    wire:model="minVoteCount"
                                    min="0"
                                    max="5000"
                                    step="50"
                                    class="accent-primary-600 h-1.5 w-full cursor-pointer appearance-none rounded-lg bg-gray-200 dark:bg-gray-700"
                                />
                                <div class="mt-0.5 flex justify-between text-[10px] text-gray-400">
                                    <span>0</span><span>2,500</span><span>5,000</span>
                                </div>
                            </div>
                        </div>

                        {{-- Row 4 (TV only): Show Status --}}
                        @if ($browseGenreType === 'tv')
                            <div>
                                <label class="mb-2 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Show Status') }}</label>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ([0 => __('Returning'), 2 => __('In Production'), 3 => __('Ended'), 4 => __('Canceled'), 1 => __('Planned'), 5 => __('Pilot')] as $value => $label)
                                        <button
                                            wire:click="toggleTvStatus({{ $value }})"
                                            @class([
                                                'inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors border',
                                                'bg-primary-600 text-white border-primary-600' => in_array(
                                                    $value,
                                                    $tvStatuses),
                                                'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-primary-400 dark:hover:border-primary-600 hover:text-primary-600 dark:hover:text-primary-400' => ! in_array(
                                                    $value,
                                                    $tvStatuses),
                                            ])
                                        >
                                            {{ $label }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Row 5: Watch Providers --}}
                        @if (count($availableProviders) > 0)
                            <div>
                                <div class="mb-2 flex items-center justify-between">
                                    <label class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Streaming On') }}</label>
                                    <select
                                        wire:model.live="watchRegion"
                                        class="focus:border-primary-500 focus:ring-primary-500 rounded-md border border-gray-300 bg-white px-2 py-1 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    >
                                        <option value="US">{{ __('US') }}</option>
                                        <option value="GB">{{ __('UK') }}</option>
                                        <option value="CA">{{ __('Canada') }}</option>
                                        <option value="AU">{{ __('Australia') }}</option>
                                        <option value="DE">{{ __('Germany') }}</option>
                                        <option value="FR">{{ __('France') }}</option>
                                        <option value="ES">{{ __('Spain') }}</option>
                                        <option value="IT">{{ __('Italy') }}</option>
                                        <option value="JP">{{ __('Japan') }}</option>
                                        <option value="KR">{{ __('South Korea') }}</option>
                                        <option value="BR">{{ __('Brazil') }}</option>
                                        <option value="MX">{{ __('Mexico') }}</option>
                                        <option value="NL">{{ __('Netherlands') }}</option>
                                        <option value="SE">{{ __('Sweden') }}</option>
                                        <option value="NO">{{ __('Norway') }}</option>
                                        <option value="IN">{{ __('India') }}</option>
                                    </select>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @foreach (array_slice($availableProviders, 0, 24) as $provider)
                                        @php $isSelected = in_array($provider['id'], $selectedProviders); @endphp
                                        <button
                                            wire:click="toggleProvider({{ $provider['id'] }})"
                                            title="{{ $provider['name'] }}"
                                            @class([
                                                'relative w-10 h-10 rounded-lg overflow-hidden transition-all border-2 flex-shrink-0',
                                                'border-primary-500 ring-2 ring-primary-500/30 shadow-md' => $isSelected,
                                                'border-transparent hover:border-gray-300 dark:hover:border-gray-500 opacity-70 hover:opacity-100' => ! $isSelected,
                                            ])
                                        >
                                            @if ($provider['logo'])
                                                <img
                                                    src="{{ $provider['logo'] }}"
                                                    alt="{{ $provider['name'] }}"
                                                    class="h-full w-full object-cover"
                                                    loading="lazy"
                                                />
                                            @else
                                                <div class="flex h-full w-full items-center justify-center bg-gray-200 px-0.5 text-center text-[9px] leading-tight font-bold text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                                                    {{ Str::limit($provider['name'], 8) }}
                                                </div>
                                            @endif
                                            @if ($isSelected)
                                                <div class="bg-primary-600/20 absolute inset-0 flex items-center justify-center">
                                                    <x-heroicon-s-check class="h-4 w-4 text-white drop-shadow" />
                                                </div>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Apply / Reset row --}}
                    <div class="flex items-center justify-end gap-2 bg-gray-50/60 px-4 pb-4 dark:bg-white/[0.02]">
                        @if ($activeFilterCount > 0)
                            <button
                                wire:click="resetFilters"
                                class="inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-gray-100"
                            >
                                <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                                {{ __('Reset All') }}
                            </button>
                        @endif
                        <button
                            wire:click="reloadBrowse"
                            class="bg-primary-600 hover:bg-primary-700 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-white transition-colors"
                        >
                            <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" />
                            {{ __('Apply') }}
                        </button>
                    </div>
                </div>

                <div class="p-4">
                    @if ($browseLoading)
                        <div class="flex items-center justify-center py-10">
                            <x-filament::loading-indicator class="text-primary-500 h-5 w-5" />
                        </div>
                    @elseif (count($browseResults) > 0)
                        <div
                            class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
                            wire:loading.class="opacity-50"
                            wire:target="goToBrowsePage"
                        >
                            @foreach ($browseResults as $item)
                                @include('livewire.partials.discover-card', ['item' => $item])
                            @endforeach
                        </div>
                        @if ($browseTotalPages > 1)
                            <div class="mt-4 flex items-center justify-center gap-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                                <button
                                    wire:click="goToBrowsePage({{ $browsePage - 1 }})"
                                    wire:loading.attr="disabled"
                                    wire:target="goToBrowsePage"
                                    @disabled($browsePage <= 1)
                                    class="p-1 rounded transition-colors {{ $browsePage <= 1 ? 'text-gray-300 dark:text-gray-600 cursor-not-allowed' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100' }}"
                                >
                                    <x-heroicon-m-chevron-left class="h-4 w-4" />
                                </button>
                                <span
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                    wire:loading.class="opacity-50"
                                    wire:target="goToBrowsePage"
                                >
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $browsePage }}</span>
                                    / {{ $browseTotalPages }}
                                </span>
                                <button
                                    wire:click="goToBrowsePage({{ $browsePage + 1 }})"
                                    wire:loading.attr="disabled"
                                    wire:target="goToBrowsePage"
                                    @disabled($browsePage >= $browseTotalPages)
                                    class="p-1 rounded transition-colors {{ $browsePage >= $browseTotalPages ? 'text-gray-300 dark:text-gray-600 cursor-not-allowed' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100' }}"
                                >
                                    <x-heroicon-m-chevron-right class="h-4 w-4" />
                                </button>
                            </div>
                        @endif
                    @else
                        <div class="flex flex-col items-center justify-center py-10 text-center">
                            <x-heroicon-o-film class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                {{ __('No results found for this genre.') }}
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        @else
            {{-- ── Discovery Content Sections ───────────────────────────────── --}}
            @php
                $discoverSections = [
                    [
                        'id' => 'trending',
                        'label' => __('Trending This Week'),
                        'icon' => 'heroicon-o-fire',
                        'iconColor' => 'warning',
                        'items' => $trendingItems,
                    ],
                    [
                        'id' => 'popular-movies',
                        'label' => __('Popular Movies'),
                        'icon' => 'heroicon-o-film',
                        'iconColor' => 'info',
                        'items' => $popularMovies,
                    ],
                    [
                        'id' => 'popular-tv',
                        'label' => __('Popular TV'),
                        'icon' => 'heroicon-o-tv',
                        'iconColor' => 'primary',
                        'items' => $popularTv,
                    ],
                    [
                        'id' => 'upcoming-movies',
                        'label' => __('Upcoming Movies'),
                        'icon' => 'heroicon-o-calendar-days',
                        'iconColor' => 'success',
                        'items' => $upcomingMovies,
                    ],
                ];
            @endphp

            @foreach ($discoverSections as $section)
                @if (count($section['items']) > 0)
                    @php
                        $initialItems = array_slice($section['items'], 0, 10);
                        $remainingItems = array_slice($section['items'], 10);
                    @endphp

                    <x-filament::section
                        :collapsible="true"
                        compact
                        heading="{{ $section['label'] }}"
                        icon="{{ $section['icon'] }}"
                        icon-color="{{ $section['iconColor'] }}"
                        class="mb-4"
                    >
                        <x-slot name="afterHeader">
                            <x-filament::badge color="gray">{{ count($section['items']) }}</x-filament::badge>
                        </x-slot>

                        <div x-data="{ expanded: false }">
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                                @foreach ($initialItems as $item)
                                    <div
                                        wire:click="requestFromDiscover({{ $item['tmdb_id'] }}, '{{ $item['media_type'] }}')"
                                        wire:loading.class="opacity-50 pointer-events-none"
                                        wire:target="requestFromDiscover"
                                        class="group relative cursor-pointer overflow-hidden rounded-lg bg-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-xl dark:bg-gray-800"
                                    >
                                        <div class="relative aspect-[2/3]">
                                            @if (! empty($item['poster_url']))
                                                <img
                                                    src="{{ $item['poster_url'] }}"
                                                    alt="{{ $item['title'] }}"
                                                    class="h-full w-full object-cover"
                                                    loading="lazy"
                                                />
                                            @else
                                                <div class="flex h-full w-full items-center justify-center bg-gray-200 dark:bg-gray-700">
                                                    @if ($item['media_type'] === 'tv')
                                                        <x-heroicon-o-tv class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                                                    @else
                                                        <x-heroicon-o-film class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                                                    @endif
                                                </div>
                                            @endif
                                            <span class="absolute top-2 left-2 rounded bg-black/60 px-1.5 py-0.5 text-xs font-semibold text-white">
                                                {{ $item['media_type'] === 'tv' ? __('TV') : __('Movie') }}
                                            </span>
                                            @if (! empty($item['isDownloaded']))
                                                <span class="absolute top-2 right-2 flex h-6 w-6 items-center justify-center rounded-full bg-green-500 shadow-sm">
                                                    <x-heroicon-s-check class="h-3.5 w-3.5 text-white" />
                                                </span>
                                            @elseif (! empty($item['existsInLibrary']))
                                                <span class="absolute top-2 right-2 flex h-6 w-6 items-center justify-center rounded-full bg-amber-500 shadow-sm">
                                                    <x-heroicon-s-bookmark class="h-3.5 w-3.5 text-white" />
                                                </span>
                                            @endif
                                            @if (! empty($item['vote_average']))
                                                <span class="absolute right-2 bottom-2 flex items-center gap-0.5 rounded bg-black/70 px-1.5 py-0.5 text-xs font-semibold text-yellow-400">
                                                    <x-heroicon-s-star class="h-3 w-3" />
                                                    {{ $item['vote_average'] }}
                                                </span>
                                            @endif
                                            <div class="absolute inset-0 flex flex-col justify-end gap-1 bg-gradient-to-t from-black/95 via-black/65 to-black/20 p-3 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
                                                <h3 class="line-clamp-2 text-sm leading-tight font-semibold text-white">
                                                    {{ $item['title'] }}
                                                    @if (! empty($item['year']))
                                                        <span class="font-normal text-white/60">({{ $item['year'] }})</span>
                                                    @endif
                                                </h3>
                                                @if (! empty($item['overview']))
                                                    <p class="line-clamp-2 text-xs text-white/55">
                                                        {{ $item['overview'] }}
                                                    </p>
                                                @endif
                                                <div class="mt-1.5">
                                                    @if (! empty($item['isDownloaded']))
                                                        <span class="flex items-center gap-1 text-xs font-medium text-green-400">
                                                            <x-heroicon-s-check class="h-3.5 w-3.5" />
                                                            {{ __('Downloaded') }}
                                                        </span>
                                                    @elseif (! empty($item['existsInLibrary']))
                                                        <span class="flex items-center gap-1 text-xs font-medium text-amber-400">
                                                            <x-heroicon-s-bookmark class="h-3.5 w-3.5" />
                                                            {{ __('Monitored') }}
                                                        </span>
                                                    @else
                                                        <span class="bg-primary-600 block w-full rounded-md px-2 py-1.5 text-center text-xs font-medium text-white">
                                                            {{ $item['media_type'] === 'tv' ? __('Select Seasons') : __('Request Movie') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Expandable remaining items --}}
                            @if (count($remainingItems) > 0)
                                <div x-show="expanded" x-collapse>
                                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                                        @foreach ($remainingItems as $item)
                                            <div
                                                wire:click="requestFromDiscover({{ $item['tmdb_id'] }}, '{{ $item['media_type'] }}')"
                                                wire:loading.class="opacity-50 pointer-events-none"
                                                wire:target="requestFromDiscover"
                                                class="group relative cursor-pointer overflow-hidden rounded-lg bg-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-xl dark:bg-gray-800"
                                            >
                                                <div class="relative aspect-[2/3]">
                                                    @if (! empty($item['poster_url']))
                                                        <img
                                                            src="{{ $item['poster_url'] }}"
                                                            alt="{{ $item['title'] }}"
                                                            class="h-full w-full object-cover"
                                                            loading="lazy"
                                                        />
                                                    @else
                                                        <div class="flex h-full w-full items-center justify-center bg-gray-200 dark:bg-gray-700">
                                                            @if ($item['media_type'] === 'tv')
                                                                <x-heroicon-o-tv class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                                                            @else
                                                                <x-heroicon-o-film class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                                                            @endif
                                                        </div>
                                                    @endif
                                                    <span class="absolute top-2 left-2 rounded bg-black/60 px-1.5 py-0.5 text-xs font-semibold text-white">
                                                        {{ $item['media_type'] === 'tv' ? __('TV') : __('Movie') }}
                                                    </span>
                                                    @if (! empty($item['isDownloaded']))
                                                        <span class="absolute top-2 right-2 flex h-6 w-6 items-center justify-center rounded-full bg-green-500 shadow-sm">
                                                            <x-heroicon-s-check class="h-3.5 w-3.5 text-white" />
                                                        </span>
                                                    @elseif (! empty($item['existsInLibrary']))
                                                        <span class="absolute top-2 right-2 flex h-6 w-6 items-center justify-center rounded-full bg-amber-500 shadow-sm">
                                                            <x-heroicon-s-bookmark class="h-3.5 w-3.5 text-white" />
                                                        </span>
                                                    @endif
                                                    @if (! empty($item['vote_average']))
                                                        <span class="absolute right-2 bottom-2 flex items-center gap-0.5 rounded bg-black/70 px-1.5 py-0.5 text-xs font-semibold text-yellow-400">
                                                            <x-heroicon-s-star class="h-3 w-3" />
                                                            {{ $item['vote_average'] }}
                                                        </span>
                                                    @endif
                                                    <div class="absolute inset-0 flex flex-col justify-end gap-1 bg-gradient-to-t from-black/95 via-black/65 to-black/20 p-3 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
                                                        <h3 class="line-clamp-2 text-sm leading-tight font-semibold text-white">
                                                            {{ $item['title'] }}
                                                            @if (! empty($item['year']))
                                                                <span class="font-normal text-white/60">({{ $item['year'] }})</span>
                                                            @endif
                                                        </h3>
                                                        @if (! empty($item['overview']))
                                                            <p class="line-clamp-2 text-xs text-white/55">
                                                                {{ $item['overview'] }}
                                                            </p>
                                                        @endif
                                                        <div class="mt-1.5">
                                                            @if (! empty($item['isDownloaded']))
                                                                <span class="flex items-center gap-1 text-xs font-medium text-green-400">
                                                                    <x-heroicon-s-check class="h-3.5 w-3.5" />
                                                                    {{ __('Downloaded') }}
                                                                </span>
                                                            @elseif (! empty($item['existsInLibrary']))
                                                                <span class="flex items-center gap-1 text-xs font-medium text-amber-400">
                                                                    <x-heroicon-s-bookmark class="h-3.5 w-3.5" />
                                                                    {{ __('Monitored') }}
                                                                </span>
                                                            @else
                                                                <span class="bg-primary-600 block w-full rounded-md px-2 py-1.5 text-center text-xs font-medium text-white">
                                                                    {{ $item['media_type'] === 'tv' ? __('Select Seasons') : __('Request Movie') }}
                                                                </span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- Show more / Show less footer --}}
                                <div class="mt-4 border-t border-gray-100 pt-3 text-center dark:border-gray-800">
                                    <button @click="expanded = ! expanded"
                                    class="text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 inline-flex items-center gap-1.5 text-xs font-medium transition-colors"
                                >
                                    <span
                                        x-show="! expanded"
                                    >{{ __('Show :n more', ['n' => count($remainingItems)]) }}</span>
                                        <span x-show="expanded" style="display: none">{{ __('Show less') }}</span>
                                        <x-heroicon-o-chevron-down
                                            class="h-3.5 w-3.5 transition-transform duration-200"
                                            x-bind:class="{ 'rotate-180': expanded }"
                                        />
                                    </button>
                                </div>
                            @endif
                        </div>
                    </x-filament::section>
                @endif
            @endforeach
        @endif

    @endif
    {{-- end @else (loadFailed) --}}
</div>
