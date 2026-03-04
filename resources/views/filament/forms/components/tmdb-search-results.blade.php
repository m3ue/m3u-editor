@php
    $results = $getState() ?? [];
    $type = $getType() ?? 'tv';
    $recordType = $type === 'tv' ? 'series' : 'vod';
    $recordIdKey = $type === 'tv' ? 'series_id' : 'vod_id';
    
    // Get record ID from sibling field
    $recordId = $this->getLivewire()->mountedActionsData[0][$recordIdKey] ?? null;
@endphp

<div class="space-y-4">
    @if(empty($results))
        <div class="text-center py-8 text-gray-500 dark:text-gray-400">
            <x-heroicon-o-magnifying-glass class="w-12 h-12 mx-auto mb-2 opacity-50" />
            <p>Enter a search query and click "Search TMDB" to find results.</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($results as $result)
                <div
                    wire:click="applyTmdbSelection({{ $result['id'] }}, '{{ $type }}', {{ $recordId ?? 'null' }}, '{{ $recordType }}')"
                    wire:loading.class="opacity-50 pointer-events-none"
                    wire:target="applyTmdbSelection"
                    class="flex gap-4 p-4 border border-gray-200 dark:border-gray-700 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                >
                    {{-- Poster --}}
                    <div class="flex-shrink-0 w-20">
                        @if(!empty($result['poster_path']))
                            <img
                                src="https://image.tmdb.org/t/p/w92{{ $result['poster_path'] }}"
                                alt="{{ $result['name'] ?? $result['title'] ?? '' }}"
                                class="w-full rounded shadow-sm"
                                loading="lazy"
                            />
                        @else
                            <div class="w-full aspect-[2/3] bg-gray-200 dark:bg-gray-700 rounded flex items-center justify-center">
                                <x-heroicon-o-film class="w-8 h-8 text-gray-400" />
                            </div>
                        @endif
                    </div>

                    {{-- Info --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <h4 class="font-semibold text-gray-900 dark:text-white truncate">
                                {{ $result['name'] ?? $result['title'] ?? 'Unknown' }}
                            </h4>
                            @if(!empty($result['vote_average']))
                                <span class="flex-shrink-0 px-2 py-0.5 text-xs font-medium rounded bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                    ⭐ {{ number_format($result['vote_average'], 1) }}
                                </span>
                            @endif
                        </div>

                        @if(!empty($result['year']))
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $result['year'] }}</p>
                        @endif

                        @if(!empty($result['original_name']) && $result['original_name'] !== ($result['name'] ?? $result['title'] ?? ''))
                            <p class="text-sm text-gray-500 dark:text-gray-500 italic truncate">
                                Original: {{ $result['original_name'] }}
                            </p>
                        @endif

                        @if(!empty($result['overview']))
                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-2 line-clamp-2">
                                {{ $result['overview'] }}
                            </p>
                        @endif

                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-500">
                            TMDB ID: <span class="font-mono">{{ $result['id'] }}</span>
                        </div>
                    </div>

                    {{-- Loading indicator --}}
                    <div wire:loading wire:target="applyTmdbSelection({{ $result['id'] }}, '{{ $type }}', {{ $recordId ?? 'null' }}, '{{ $recordType }}')" class="flex-shrink-0 flex items-center">
                        <x-filament::loading-indicator class="w-5 h-5" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
