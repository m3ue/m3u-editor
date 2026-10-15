<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php
        $results = $getResults();
        $type = $getType();
        $recordType = $type === 'tv' ? 'series' : 'vod';
        $selectStatePath = $getSelectStatePath();
        $selectedId = $selectStatePath ? $getSelectedId() : null;
        $recordId = $selectStatePath ? null : $getRecordId();
    @endphp

    <div class="space-y-4">
        @if (empty($results))
            <div class="py-8 text-center text-gray-500 dark:text-gray-400">
                <x-heroicon-o-magnifying-glass class="mx-auto mb-2 h-12 w-12 opacity-50" />
                <p>Enter a search query and click "Search TMDB" to find results.</p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($results as $result)
                    @php($isSelected = $selectedId === (int) $result['id'])
                    <div
                        @if ($selectStatePath)
                            wire:click="$set('{{ $selectStatePath }}', {{ (int) $result['id'] }})"
                        @else
                            wire:click="applyTmdbSelection({{ $result['id'] }}, '{{ $type }}', {{ $recordId ?? 'null' }}, '{{ $recordType }}')"
                            wire:loading.class="opacity-50 pointer-events-none"
                            wire:target="applyTmdbSelection"
                        @endif
                        @class([
                            'flex cursor-pointer gap-4 rounded-lg border p-4 transition-colors',
                            'border-primary-500 bg-primary-50 ring-primary-500 ring-1 dark:bg-primary-950/40' => $isSelected,
                            'border-gray-200 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800' => ! $isSelected,
                        ])
                    >
                        {{-- Poster --}}
                        <div class="w-20 flex-shrink-0">
                            @if (! empty($result['poster_path']))
                                <img
                                    src="https://image.tmdb.org/t/p/w92{{ $result['poster_path'] }}"
                                    alt="{{ $result['name'] ?? $result['title'] ?? '' }}"
                                    class="w-full rounded shadow-sm"
                                    loading="lazy"
                                />
                            @else
                                <div class="flex aspect-[2/3] w-full items-center justify-center rounded bg-gray-200 dark:bg-gray-700">
                                    <x-heroicon-o-film class="h-8 w-8 text-gray-400" />
                                </div>
                            @endif
                        </div>

                        {{-- Info --}}
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <h4 class="truncate font-semibold text-gray-900 dark:text-white">
                                    {{ $result['name'] ?? $result['title'] ?? 'Unknown' }}
                                </h4>
                                @if (! empty($result['vote_average']))
                                    <span class="flex-shrink-0 rounded bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                        ⭐ {{ number_format($result['vote_average'], 1) }}
                                    </span>
                                @endif
                            </div>

                            @if (! empty($result['year']))
                                <p class="text-sm text-gray-600 dark:text-gray-400">{{ $result['year'] }}</p>
                            @endif

                            @if (! empty($result['original_name']) && $result['original_name'] !== ($result['name'] ?? $result['title'] ?? ''))
                                <p class="truncate text-sm text-gray-500 italic dark:text-gray-500">
                                    Original: {{ $result['original_name'] }}
                                </p>
                            @endif

                            @if (! empty($result['overview']))
                                <p class="mt-2 line-clamp-2 text-sm text-gray-600 dark:text-gray-400">
                                    {{ $result['overview'] }}
                                </p>
                            @endif

                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-500">
                                TMDB ID: <span class="font-mono">{{ $result['id'] }}</span>
                            </div>
                        </div>

                        @if ($isSelected)
                            <div class="flex flex-shrink-0 items-center">
                                <x-filament::icon icon="heroicon-s-check-circle" class="text-primary-500 h-6 w-6" />
                            </div>
                        @endif

                        {{-- Loading indicator --}}
                        @unless ($selectStatePath)
                            <div
                                wire:loading
                                wire:target="applyTmdbSelection({{ $result['id'] }}, '{{ $type }}', {{ $recordId ?? 'null' }}, '{{ $recordType }}')"
                                class="flex flex-shrink-0 items-center"
                            >
                                <x-filament::loading-indicator class="h-5 w-5" />
                            </div>
                        @endunless
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-dynamic-component>
