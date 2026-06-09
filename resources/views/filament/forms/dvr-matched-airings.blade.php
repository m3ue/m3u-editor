@if(!empty($airings))
    <div class="col-span-full fi-fo-field-wrp">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">
            {{ __('Upcoming Airings') }}
            <span class="ml-1 text-xs font-normal text-gray-400 dark:text-gray-500">({{ count($airings) }} {{ __('in next 14 days') }})</span>
        </h3>
        <div class="space-y-1.5">
            @foreach($airings as $airing)
                <div class="rounded-lg border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-gray-800/50 overflow-hidden">
                    <div class="flex items-start justify-between gap-3 px-3 py-2">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                {{-- Channel + time --}}
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300 truncate">
                                    {{ $airing['channel_name'] }}
                                </span>
                                <span class="text-xs text-gray-400" aria-hidden="true">&middot;</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400 flex-shrink-0">
                                    {{ $airing['start_time_human'] }}
                                </span>

                                {{-- S/E badge --}}
                                @if($airing['season'] || $airing['episode'])
                                    <span class="font-mono text-xs font-semibold text-primary-600 dark:text-primary-400 flex-shrink-0">
                                        S{{ str_pad($airing['season'] ?? '?', 2, '0', STR_PAD_LEFT) }}E{{ str_pad($airing['episode'] ?? '?', 2, '0', STR_PAD_LEFT) }}
                                    </span>
                                @endif

                                {{-- Flags --}}
                                @if($airing['is_new'])
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-500/90 text-white flex-shrink-0">{{ __('New') }}</span>
                                @endif
                                @if($airing['premiere'])
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-purple-500/90 text-white flex-shrink-0">{{ __('Premiere') }}</span>
                                @endif
                            </div>

                            {{-- Episode subtitle --}}
                            @if($airing['subtitle'])
                                <p class="text-xs text-gray-600 dark:text-gray-300 mt-0.5 truncate">{{ $airing['subtitle'] }}</p>
                            @endif

                            {{-- Description --}}
                            @if($airing['description'])
                                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 line-clamp-2">{{ $airing['description'] }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@else
    <div class="col-span-full fi-fo-field-wrp">
        <div class="rounded-lg border border-dashed border-gray-300 dark:border-white/10 px-4 py-6 text-center">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('No upcoming airings found for this series in the next 14 days.') }}
            </p>
        </div>
    </div>
@endif
