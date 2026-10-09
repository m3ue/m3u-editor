<div>
    <!-- TMDB Search Modal -->
    <div
        x-data="{ show: @entangle('showModal') }"
        x-show="show"
        x-transition.opacity.duration.300ms
        x-on:open-tmdb-search.window="$wire.openSearch($event.detail)"
        class="fixed inset-0 z-50 overflow-y-auto"
        style="display: none"
    >
        <div class="flex min-h-screen items-center justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <!-- Backdrop -->
            <div class="fixed inset-0 bg-black/75 transition-opacity" @click="$wire.closeModal()"></div>

            <!-- Modal Content -->
            <div
                class="my-8 inline-block w-full max-w-4xl transform overflow-hidden rounded-lg bg-white text-left align-middle shadow-xl transition-all dark:bg-gray-900"
                x-show="show"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 transform translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 transform translate-y-0 sm:scale-100"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 transform translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 transform translate-y-4 sm:translate-y-0 sm:scale-95"
            >
                <!-- Header -->
                <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-700">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Manual TMDB Search</h3>
                        @if ($originalTitle)
                            <p class="text-sm text-gray-500 dark:text-gray-400">Original: {{ $originalTitle }}</p>
                        @endif
                    </div>
                    <button
                        @click="$wire.closeModal()"
                        class="text-gray-400 hover:text-gray-600 focus:outline-none dark:text-gray-500 dark:hover:text-gray-300"
                    >
                        <x-heroicon-o-x-mark class="h-6 w-6" />
                    </button>
                </div>

                <!-- Search Form -->
                <div class="border-b border-gray-200 p-4 dark:border-gray-700">
                    <form wire:submit="search" class="flex flex-col gap-3 sm:flex-row">
                        <div class="flex-1">
                            <label for="search-query" class="sr-only">Search Title</label>
                            <input
                                type="text"
                                id="search-query"
                                wire:model="searchQuery"
                                placeholder="Enter title (e.g., Everybody Hates Chris)"
                                class="focus:border-primary-500 focus:ring-primary-500 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                            />
                        </div>
                        <div class="w-full sm:w-28">
                            <label for="search-year" class="sr-only">Year</label>
                            <input
                                type="number"
                                id="search-year"
                                wire:model="searchYear"
                                placeholder="Year"
                                min="1900"
                                max="{{ date('Y') + 2 }}"
                                class="focus:border-primary-500 focus:ring-primary-500 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                            />
                        </div>
                        <button
                            type="submit"
                            class="bg-primary-600 hover:bg-primary-700 focus:ring-primary-500 inline-flex items-center justify-center rounded-lg border border-transparent px-4 py-2 text-sm font-medium text-white focus:ring-2 focus:ring-offset-2 focus:outline-none disabled:opacity-50"
                            wire:loading.attr="disabled"
                        >
                            <x-heroicon-o-magnifying-glass
                                class="mr-2 h-4 w-4"
                                wire:loading.remove
                                wire:target="search"
                            />
                            <x-filament::loading-indicator class="mr-2 h-4 w-4" wire:loading wire:target="search" />
                            Search
                        </button>
                    </form>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Tip: Try searching in English for better results. The TMDB database has the most complete data
                        in English.
                    </p>
                </div>

                <!-- Results -->
                <div class="max-h-[60vh] overflow-y-auto">
                    @if ($isSearching)
                        <div class="flex items-center justify-center p-8">
                            <x-filament::loading-indicator class="text-primary-500 h-8 w-8" />
                            <span class="ml-3 text-gray-600 dark:text-gray-400">Searching TMDB...</span>
                        </div>
                    @elseif (count($results) > 0)
                        <div class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($results as $result)
                                <div
                                    class="flex cursor-pointer p-4 transition-colors hover:bg-gray-50 dark:hover:bg-gray-800"
                                    wire:click="selectResult({{ $result['tmdb_id'] }})"
                                    wire:loading.class="opacity-50 pointer-events-none"
                                >
                                    <!-- Poster -->
                                    <div class="w-16 flex-shrink-0 sm:w-20">
                                        @if ($result['poster_path'])
                                            <img
                                                src="{{ $result['poster_path'] }}"
                                                alt="{{ $result['name'] ?? $result['title'] ?? '' }}"
                                                class="w-full rounded shadow-sm"
                                                loading="lazy"
                                            />
                                        @else
                                            <div class="flex aspect-[2/3] w-full items-center justify-center rounded bg-gray-200 dark:bg-gray-700">
                                                <x-heroicon-o-film class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Info -->
                                    <div class="ml-4 min-w-0 flex-1">
                                        <div class="flex items-start justify-between">
                                            <div>
                                                <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                                    {{ $result['name'] ?? $result['title'] ?? 'Unknown' }}
                                                    @if ($result['year'])
                                                        <span class="text-gray-500 dark:text-gray-400">({{ $result['year'] }})</span>
                                                    @endif
                                                </h4>
                                                @if (($result['original_name'] ?? $result['original_title'] ?? null) && ($result['original_name'] ?? $result['original_title']) !== ($result['name'] ?? $result['title']))
                                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                        Original: {{ $result['original_name'] ?? $result['original_title'] }}
                                                    </p>
                                                @endif
                                            </div>
                                            <div class="ml-2 flex flex-shrink-0 items-center space-x-2">
                                                @if ($result['vote_average'])
                                                    <span class="inline-flex items-center rounded bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-500">
                                                        <x-heroicon-s-star class="mr-1 h-3 w-3" />
                                                        {{ number_format($result['vote_average'], 1) }}
                                                    </span>
                                                @endif
                                                @if (! empty($result['origin_country']))
                                                    <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                                        {{ $result['origin_country'] }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>

                                        @if ($result['overview'])
                                            <p class="mt-1 line-clamp-2 text-xs text-gray-600 dark:text-gray-400">
                                                {{ $result['overview'] }}
                                            </p>
                                        @endif

                                        <div class="mt-2 flex items-center space-x-3 text-xs text-gray-500 dark:text-gray-400">
                                            <span class="inline-flex items-center">
                                                <span class="font-medium">TMDB:</span>
                                                <span class="ml-1">{{ $result['tmdb_id'] }}</span>
                                            </span>
                                            @if ($result['first_air_date'] ?? $result['release_date'] ?? null)
                                                <span class="inline-flex items-center">
                                                    <x-heroicon-o-calendar class="mr-1 h-3 w-3" />
                                                    {{ $result['first_air_date'] ?? $result['release_date'] }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Select Arrow -->
                                    <div class="ml-2 flex-shrink-0 self-center">
                                        <x-heroicon-o-chevron-right class="h-5 w-5 text-gray-400" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($searchQuery && ! $isSearching)
                        <div class="flex flex-col items-center justify-center p-8 text-center">
                            <x-heroicon-o-magnifying-glass class="h-12 w-12 text-gray-300 dark:text-gray-600" />
                            <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No results found</h4>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Try a different search term or remove the year filter.
                            </p>
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center p-8 text-center">
                            <x-heroicon-o-film class="h-12 w-12 text-gray-300 dark:text-gray-600" />
                            <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">Search TMDB</h4>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Enter a title to search for movies or TV series.
                            </p>
                        </div>
                    @endif
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-end gap-3 border-t border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                    <x-filament::button color="gray" x-on:click="$wire.closeModal()">
                        {{ __('Cancel') }}
                    </x-filament::button>
                </div>
            </div>
        </div>
    </div>
</div>
