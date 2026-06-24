@if (empty($items))
    <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-8">
        {{ __('No filmography available.') }}
    </p>
@else
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
        @foreach ($items as $item)
            @php
                $isTv = ($item['media_type'] ?? 'movie') === 'tv';
                $tmdbId = (int) ($item['tmdb_id'] ?? 0);
                $mediaType = $item['media_type'] ?? 'movie';
            @endphp
            <div wire:click="openFilmographyItem({{ $tmdbId }}, '{{ $mediaType }}')"
                class="group relative rounded-lg overflow-hidden shadow-sm hover:shadow-xl transition-shadow duration-200 bg-gray-200 dark:bg-gray-800 cursor-pointer">
                <div class="relative aspect-[2/3]">
                    @if (!empty($item['poster_url']))
                        <img src="{{ $item['poster_url'] }}" alt="{{ $item['title'] ?? '' }}"
                            class="w-full h-full object-cover" loading="lazy">
                    @else
                        <div class="w-full h-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                            @if ($isTv)
                                <x-heroicon-o-tv class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                            @else
                                <x-heroicon-o-film class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                            @endif
                        </div>
                    @endif
                    <span class="absolute top-2 left-2 px-1.5 py-0.5 text-xs font-semibold rounded bg-black/60 text-white">
                        {{ $isTv ? __('TV') : __('Movie') }}
                    </span>
                    @if (!empty($item['year']))
                        <span class="absolute top-2 right-2 px-1.5 py-0.5 text-xs font-medium rounded bg-black/60 text-white">
                            {{ $item['year'] }}
                        </span>
                    @endif
                    <div class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-200 bg-gradient-to-t from-black/95 via-black/65 to-black/20 flex flex-col justify-end p-3 gap-1">
                        <h3 class="text-white font-semibold text-sm leading-tight line-clamp-2">
                            {{ $item['title'] ?? '' }}
                        </h3>
                        @if (!empty($item['character']))
                            <p class="text-white/70 text-xs line-clamp-1">
                                {{ __('as') }} {{ $item['character'] }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
