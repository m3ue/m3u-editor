<x-filament-widgets::widget>
    <x-filament::section compact>
        <div class="flex flex-wrap items-center gap-2">
            <span class="mr-1 text-xs font-medium tracking-wide text-gray-400 uppercase dark:text-gray-500">
                {{ __('Quick actions') }}
            </span>

            @foreach ($actions as $action)
                <x-filament::button
                    tag="a"
                    href="{{ $action['url'] }}"
                    :color="$action['color']"
                    :icon="$action['icon']"
                    size="sm"
                >
                    {{ $action['label'] }}
                </x-filament::button>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
