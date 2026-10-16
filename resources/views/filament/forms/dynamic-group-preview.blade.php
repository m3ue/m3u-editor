@if ($error)
    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-white/10 dark:bg-gray-800/50 dark:text-gray-300">
        {{ $error }}
    </div>
@else
    <div class="space-y-4 text-sm">
        @if (! empty($outOfSeason))
            <div class="border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-700 dark:bg-warning-900/30 dark:text-warning-200 rounded-lg border px-3 py-2">
                {{ __('This theme rule is currently outside its seasonal window. The group will hide itself on the next sync and reappear when the window opens again.') }}
            </div>
        @endif

        {{-- Summary --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::badge :color="$matchedTotal > 0 ? 'success' : 'warning'">
                {{ trans_choice(':count entry matched|:count entries matched', $matchedTotal, ['count' => $matchedTotal]) }}
            </x-filament::badge>
            @if (empty($isTheme))
                <span class="text-gray-500 dark:text-gray-400">
                    {{ __(':total titles returned by TMDB for this rule.', ['total' => $tmdbTotal]) }}
                </span>
            @endif
        </div>

        @if ($matchedTotal === 0 && ! empty($isTheme))
            <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-gray-600 dark:border-white/10 dark:bg-gray-800/50 dark:text-gray-300">
                {{ __('Nothing in this playlist matches the theme\'s keywords or search terms yet. Keyword matches fill in as titles are enriched from TMDB.') }}
            </div>
        @elseif ($matchedTotal === 0)
            <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-gray-600 dark:border-white/10 dark:bg-gray-800/50 dark:text-gray-300">
                {{
                    $type === 'series'
                    ? __('Entries match by TMDB ID. If your series are missing TMDB IDs, run a playlist sync with TMDB matching enabled first.')
                    : __('Entries match by TMDB ID. If your VOD channels are missing TMDB IDs, run a playlist sync with TMDB matching enabled first.')
                }}
            </div>
        @else
            {{-- Matched entries --}}
            <div>
                <h4 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">{{ __('In this playlist') }}</h4>
                <ul class="max-h-64 space-y-1 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-2 dark:border-white/10 dark:bg-gray-800/50">
                    @foreach ($matched as $name)
                        <li class="flex items-center gap-2 truncate text-gray-700 dark:text-gray-200">
                            <x-filament::icon
                                icon="heroicon-o-check-circle"
                                class="text-success-500 h-4 w-4 flex-shrink-0"
                            />
                            <span class="truncate">{{ $name }}</span>
                        </li>
                    @endforeach
                </ul>
                @if ($matchedTotal > count($matched))
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                        {{ __('and :count more…', ['count' => $matchedTotal - count($matched)]) }}
                    </p>
                @endif
            </div>
        @endif

        {{-- TMDB titles with no matching entry --}}
        @if ($unmatchedTotal > 0)
            <div>
                <h4 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">
                    {{ __('Not in this playlist') }}
                    <span class="ml-1 text-xs font-normal text-gray-400 dark:text-gray-500">({{ $unmatchedTotal }})</span>
                </h4>
                <ul class="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-white/10">
                    @foreach ($unmatched as $item)
                        <li class="truncate text-gray-400 dark:text-gray-500">
                            {{ $item['title'] ?? __('Unknown') }}
                            @if (! empty($item['year'])) ({{ $item['year'] }})@endif
                        </li>
                    @endforeach
                </ul>
                @if ($unmatchedTotal > count($unmatched))
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                        {{ __('and :count more…', ['count' => $unmatchedTotal - count($unmatched)]) }}
                    </p>
                @endif
            </div>
        @endif
    </div>
@endif
