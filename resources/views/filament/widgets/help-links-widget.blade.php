<x-filament-widgets::widget>
    <x-filament::section compact>
        <div class="flex flex-wrap items-center gap-2">
            <span class="mr-1 text-xs font-medium tracking-wide text-gray-400 uppercase dark:text-gray-500">
                {{ __('Help & community') }}
            </span>

            @foreach ($links as $link)
                <x-filament::button
                    tag="a"
                    href="{{ $link['url'] }}"
                    :color="$link['color']"
                    :icon="$link['icon']"
                    size="sm"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    {{ $link['label'] }}
                </x-filament::button>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
