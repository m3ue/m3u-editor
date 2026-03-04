<div class="space-y-4">
    {{-- Filters --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label for="vod-search" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Search</label>
            <input
                id="vod-search"
                wire:model.live.debounce.300ms="search"
                type="search"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:focus:border-primary-500 sm:text-sm"
                placeholder="Movie title..."
            />
        </div>

        <div class="w-48">
            <label for="vod-genre" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Genre</label>
            <select
                id="vod-genre"
                wire:model.live="genreFilter"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white dark:[&>option]:bg-gray-800 dark:[&>option]:text-white sm:text-sm"
            >
                <option value="">All Genres</option>
                @foreach($this->genreOptions as $genre)
                    <option value="{{ $genre }}">{{ $genre }}</option>
                @endforeach
            </select>
        </div>

        <div class="w-32">
            <label for="vod-perPage" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Per page</label>
            <select
                id="vod-perPage"
                wire:model.live="perPage"
                class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white dark:[&>option]:bg-gray-800 dark:[&>option]:text-white sm:text-sm"
            >
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
            </select>
        </div>
    </div>

    {{-- Table --}}
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="w-12 px-4 py-3">
                        <input
                            type="checkbox"
                            wire:click="toggleSelectAllOnPage"
                            class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:checked:bg-primary-500"
                            aria-label="Select all on page"
                        />
                    </th>
                    <th wire:click="sortBy('name')" class="cursor-pointer px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        <span class="inline-flex items-center gap-1">
                            Title
                            @if($sortBy === 'name')
                                @if($sortDirection === 'asc')
                                    <x-heroicon-m-chevron-up class="h-4 w-4" />
                                @else
                                    <x-heroicon-m-chevron-down class="h-4 w-4" />
                                @endif
                            @endif
                        </span>
                    </th>
                    <th wire:click="sortBy('genre')" class="cursor-pointer px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        <span class="inline-flex items-center gap-1">
                            Genre
                            @if($sortBy === 'genre')
                                @if($sortDirection === 'asc')
                                    <x-heroicon-m-chevron-up class="h-4 w-4" />
                                @else
                                    <x-heroicon-m-chevron-down class="h-4 w-4" />
                                @endif
                            @endif
                        </span>
                    </th>
                    <th wire:click="sortBy('rating')" class="w-20 cursor-pointer px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        <span class="inline-flex items-center gap-1">
                            Rating
                            @if($sortBy === 'rating')
                                @if($sortDirection === 'asc')
                                    <x-heroicon-m-chevron-up class="h-4 w-4" />
                                @else
                                    <x-heroicon-m-chevron-down class="h-4 w-4" />
                                @endif
                            @endif
                        </span>
                    </th>
                    <th wire:click="sortBy('mpaa')" class="w-20 cursor-pointer px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        <span class="inline-flex items-center gap-1">
                            MPAA
                            @if($sortBy === 'mpaa')
                                @if($sortDirection === 'asc')
                                    <x-heroicon-m-chevron-up class="h-4 w-4" />
                                @else
                                    <x-heroicon-m-chevron-down class="h-4 w-4" />
                                @endif
                            @endif
                        </span>
                    </th>
                    <th wire:click="sortBy('runtime')" class="w-24 cursor-pointer px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        <span class="inline-flex items-center gap-1">
                            Runtime
                            @if($sortBy === 'runtime')
                                @if($sortDirection === 'asc')
                                    <x-heroicon-m-chevron-up class="h-4 w-4" />
                                @else
                                    <x-heroicon-m-chevron-down class="h-4 w-4" />
                                @endif
                            @endif
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/5 dark:bg-gray-900">
                @forelse($vods as $vod)
                    @php
                        $info = $vod->info ?? [];
                        $rating = $info['rating'] ?? null;
                        $genre = $info['genre'] ?? null;
                        $mpaa = $info['mpaa_rating'] ?? null;
                        $durationSecs = $info['duration_secs'] ?? null;
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3">
                            <input
                                type="checkbox"
                                wire:model.live="selected"
                                value="{{ $vod->id }}"
                                class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:checked:bg-primary-500"
                            />
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-950 dark:text-white">{{ $vod->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                            @if($genre)
                                <span class="max-w-[200px] truncate block" title="{{ $genre }}">{{ Str::limit($genre, 30) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @if($rating)
                                <span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400">
                                    <x-heroicon-m-star class="h-4 w-4" />
                                    {{ number_format((float) $rating, 1) }}
                                </span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @if($mpaa)
                                <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-500/10 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10">
                                    {{ $mpaa }}
                                </span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                            @if($durationSecs)
                                @php
                                    $hours = floor($durationSecs / 3600);
                                    $minutes = floor(($durationSecs % 3600) / 60);
                                @endphp
                                {{ $hours > 0 ? "{$hours}h " : '' }}{{ $minutes }}m
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            No movies found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Footer --}}
    <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Showing {{ $vods->firstItem() ?? 0 }}–{{ $vods->lastItem() ?? 0 }} of {{ $vods->total() }}
        </p>

        <div class="flex items-center gap-4">
            {{ $vods->links() }}

            <button
                wire:click="addSelected"
                type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-success-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition duration-75 hover:bg-success-500 focus:outline-none focus:ring-2 focus:ring-success-500 focus:ring-offset-2 dark:bg-success-500 dark:hover:bg-success-400 dark:focus:ring-offset-gray-900"
            >
                <x-heroicon-m-plus class="h-4 w-4" />
                Add Selected ({{ count($selected) }})
            </button>
        </div>
    </div>
</div>
