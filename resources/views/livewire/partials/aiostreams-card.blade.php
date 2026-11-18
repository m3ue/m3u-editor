@php
    $itemType = (string) ($item['type'] ?? 'movie');
    $isSeries = $itemType === 'series';
    // Catalog types come straight from the addon manifest (tv, channel, anime,
    // ...), but only movies and series can be added to the library.
    $isSelectable = in_array($itemType, ['movie', 'series'], true);
    $selectionItem = ['type' => $itemType, 'id' => (string) $item['id']];
    $selectionKey = $itemType.':'.$selectionItem['id'];
    // Built here rather than with @js() in the attributes: Blade doesn't compile
    // directives inside a component tag's attributes (the checkbox below).
    $selectionItemJs = \Illuminate\Support\Js::from($selectionItem);
    $selectionKeyJs = \Illuminate\Support\Js::from($selectionKey);
@endphp
{{--
    Selection state lives in the `aioSelection` Alpine store (registered in
    aio-streams-browse.blade.php). While anything is selected, clicking a card
    toggles it instead of opening the detail slide-over.
--}}
<div
    wire:key="item-{{ $item['id'] }}-{{ $item['type'] ?? '' }}"
    @if ($isSelectable)
        x-on:click="$store.aioSelection?.canSelect && $store.aioSelection.any() ? $store.aioSelection.toggle({{ $selectionItemJs }}) : $wire.dispatch('openAioDetail', {{ $selectionItemJs }})"
        x-bind:class="$store.aioSelection?.has({{ $selectionKeyJs }}) && 'ring-4 ring-primary-500'"
    @else
        x-on:click="$wire.dispatch('openAioDetail', {{ $selectionItemJs }})"
    @endif
    class="group relative cursor-pointer overflow-hidden rounded-lg bg-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-xl dark:bg-gray-800"
>
    <div class="relative aspect-[2/3]">
        @if ($isSelectable)
            <template x-if="$store.aioSelection?.canSelect">
                <label
                    x-on:click.stop
                    class="absolute top-1 right-1 z-10 flex cursor-pointer items-center justify-center rounded-md bg-black/60 p-2.5 hover:bg-black/80"
                    title="{{ __('Select') }}"
                >
                    <x-filament::input.checkbox
                        class="size-5"
                        x-bind:checked="$store.aioSelection.has({{ $selectionKeyJs }})"
                        x-on:change="$store.aioSelection.toggle({{ $selectionItemJs }})"
                    />
                </label>
            </template>
        @endif
        @if (! empty($item['poster']))
            <img
                src="{{ $item['poster'] }}"
                alt="{{ $item['name'] ?? '' }}"
                class="h-full w-full object-cover"
                loading="lazy"
            />
        @else
            <div class="flex h-full w-full items-center justify-center bg-gray-200 dark:bg-gray-700">
                @if ($isSeries)
                    <x-heroicon-o-tv class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                @else
                    <x-heroicon-o-film class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                @endif
            </div>
        @endif
        <span class="absolute top-2 left-2 rounded bg-black/60 px-1.5 py-0.5 text-xs font-semibold text-white">
            {{ $isSeries ? __('Series') : __('Movie') }}
        </span>
        @if (! empty($item['imdbRating']))
            <span class="absolute right-2 bottom-2 flex items-center gap-0.5 rounded bg-black/70 px-1.5 py-0.5 text-xs font-semibold text-yellow-400">
                <x-heroicon-s-star class="h-3 w-3" />
                {{ $item['imdbRating'] }}
            </span>
        @endif
        <div class="absolute inset-0 flex flex-col justify-end gap-1 bg-gradient-to-t from-black/95 via-black/65 to-black/20 p-3 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
            <h3 class="line-clamp-2 text-sm leading-tight font-semibold text-white">
                {{ $item['name'] ?? '' }}
                @if (! empty($item['releaseInfo']))
                    <span class="font-normal text-white/60">({{ $item['releaseInfo'] }})</span>
                @endif
            </h3>
            @if (! empty($item['description']))
                <p class="line-clamp-2 text-xs text-white/55">{{ $item['description'] }}</p>
            @endif
        </div>
    </div>
</div>
