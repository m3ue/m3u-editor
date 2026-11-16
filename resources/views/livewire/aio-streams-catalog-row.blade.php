<div x-intersect="$wire.loadIfVisible()">
    <x-filament::section
        :collapsible="true"
        compact
        heading="{{ $catalogName }}"
        icon="{{ $catalogType == 'movie' ? 'heroicon-o-film' : 'heroicon-o-tv' }}"
        icon-color="gray"
    >
        @if ($loaded && ! empty($items))
            <x-slot name="afterHeader">
                <x-filament::badge color="gray">{{ count($items) }}</x-filament::badge>
            </x-slot>
        @endif

        @if (! $loaded)
            <div class="flex items-center justify-center py-10">
                <x-filament::loading-indicator class="text-primary-500 h-5 w-5" />
            </div>
        @elseif ($loadFailed)
            <div class="flex flex-col items-center justify-center py-6 text-center">
                <x-heroicon-o-exclamation-triangle class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Failed to load this catalog') }}</p>
            </div>
        @elseif (empty($items))
            <p class="py-4 text-center text-xs text-gray-400 dark:text-gray-500">
                {{ __('No items in this catalog') }}
            </p>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                @foreach ($items as $item)
                    @include('livewire.partials.aiostreams-card', ['item' => $item])
                @endforeach
            </div>

            @if ($hasMore)
                <div class="mt-4 flex justify-center border-t border-gray-100 pt-3 dark:border-gray-800">
                    <x-filament::button
                        color="gray"
                        size="sm"
                        icon="heroicon-o-chevron-down"
                        wire:click="loadMore"
                        wire:target="loadMore"
                    >
                        {{ __('Load more') }}
                    </x-filament::button>
                </div>
            @endif
        @endif
    </x-filament::section>
</div>
