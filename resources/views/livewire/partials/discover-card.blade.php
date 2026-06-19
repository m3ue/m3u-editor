<div wire:click="requestFromDiscover({{ $item['tmdb_id'] }}, '{{ $item['media_type'] }}')"
    wire:loading.class="opacity-50 pointer-events-none" wire:target="requestFromDiscover"
    class="group relative cursor-pointer rounded-lg overflow-hidden shadow-sm hover:shadow-xl transition-shadow duration-200 bg-gray-200 dark:bg-gray-800">
    <div class="relative aspect-[2/3]">
        @if (!empty($item['poster_url']))
            <img src="{{ $item['poster_url'] }}" alt="{{ $item['title'] }}" class="w-full h-full object-cover"
                loading="lazy">
        @else
            <div class="w-full h-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                @if ($item['media_type'] === 'tv')
                    <x-heroicon-o-tv class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                @else
                    <x-heroicon-o-film class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                @endif
            </div>
        @endif
        <span class="absolute top-2 left-2 px-1.5 py-0.5 text-xs font-semibold rounded bg-black/60 text-white">
            {{ $item['media_type'] === 'tv' ? __('TV') : __('Movie') }}
        </span>
        @if (!empty($item['isDownloaded']))
            <span
                class="absolute top-2 right-2 w-6 h-6 rounded-full bg-green-500 flex items-center justify-center shadow-sm">
                <x-heroicon-s-check class="w-3.5 h-3.5 text-white" />
            </span>
        @elseif(!empty($item['existsInLibrary']))
            <span
                class="absolute top-2 right-2 w-6 h-6 rounded-full bg-amber-500 flex items-center justify-center shadow-sm">
                <x-heroicon-s-bookmark class="w-3.5 h-3.5 text-white" />
            </span>
        @endif
        @if (!empty($item['vote_average']))
            <span
                class="absolute bottom-2 right-2 flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-black/70 text-yellow-400 text-xs font-semibold">
                <x-heroicon-s-star class="w-3 h-3" />
                {{ $item['vote_average'] }}
            </span>
        @endif
        <div
            class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-200 bg-gradient-to-t from-black/95 via-black/65 to-black/20 flex flex-col justify-end p-3 gap-1">
            <h3 class="text-white font-semibold text-sm leading-tight line-clamp-2">
                {{ $item['title'] }}
                @if (!empty($item['year']))
                    <span class="text-white/60 font-normal">({{ $item['year'] }})</span>
                @endif
            </h3>
            @if (!empty($item['overview']))
                <p class="text-white/55 text-xs line-clamp-2">{{ $item['overview'] }}</p>
            @endif
            <div class="mt-1.5">
                @if (!empty($item['isDownloaded']))
                    <span class="flex items-center gap-1 text-green-400 text-xs font-medium">
                        <x-heroicon-s-check class="w-3.5 h-3.5" />
                        {{ __('Downloaded') }}
                    </span>
                @elseif(!empty($item['existsInLibrary']))
                    <span class="flex items-center gap-1 text-amber-400 text-xs font-medium">
                        <x-heroicon-s-bookmark class="w-3.5 h-3.5" />
                        {{ __('Monitored') }}
                    </span>
                @else
                    <span
                        class="block w-full text-center px-2 py-1.5 rounded-md bg-primary-600 text-white text-xs font-medium">
                        {{ $item['media_type'] === 'tv' ? __('Select Seasons') : __('Request Movie') }}
                    </span>
                @endif
            </div>
        </div>
    </div>
</div>
