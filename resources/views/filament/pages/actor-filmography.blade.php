<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <div class="w-20 h-20 rounded-full overflow-hidden flex-shrink-0 bg-gray-200 dark:bg-gray-700">
                @if (!empty($person['photo']))
                    <img src="{{ $person['photo'] }}" alt="{{ $person['name'] }}" class="w-full h-full object-cover">
                @else
                    <div class="w-full h-full flex items-center justify-center">
                        <x-heroicon-o-user class="w-8 h-8 text-gray-400" />
                    </div>
                @endif
            </div>
            <div class="flex-1 min-w-0">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {{ $person['name'] ?? $name ?: __('Actor') }}
                </h1>
                @if (!empty($person['bio']))
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400 line-clamp-3">{{ $person['bio'] }}</p>
                @endif
            </div>
        </div>

        @include('filament.partials.filmography-grid', ['items' => $filmography])
    </div>

    <livewire:arr-search :detail-only="true" wire:key="filmography-arr-search" />
</x-filament-panels::page>