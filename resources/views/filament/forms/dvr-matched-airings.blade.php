@if (! empty($airings))
    <div class="fi-fo-field-wrp col-span-full">
        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">
            {{ __('Upcoming Airings') }}
            <span class="ml-1 text-xs font-normal text-gray-400 dark:text-gray-500">({{ count($airings) }} {{ __('in next 14 days') }})</span>
        </h3>
        <div class="space-y-1.5">
            @foreach ($airings as $airing)
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-800/50">
                    <div class="flex items-start justify-between gap-3 px-3 py-2">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                {{-- Channel + time --}}
                                <span class="truncate text-xs font-medium text-gray-700 dark:text-gray-300">
                                    {{ $airing['channel_name'] }}
                                </span>
                                <span class="text-xs text-gray-400" aria-hidden="true">&middot;</span>
                                <span class="flex-shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $airing['start_time_human'] }}
                                </span>

                                {{-- S/E badge --}}
                                @if ($airing['season'] || $airing['episode'])
                                    <span class="text-primary-600 dark:text-primary-400 flex-shrink-0 font-mono text-xs font-semibold">
                                        S{{ str_pad($airing['season'] ?? '?', 2, '0', STR_PAD_LEFT) }}E{{ str_pad($airing['episode'] ?? '?', 2, '0', STR_PAD_LEFT) }}
                                    </span>
                                @endif

                                {{-- Will-record badge --}}
                                @if (isset($airing['will_record']))
                                    @if ($airing['will_record'])
                                        <span class="inline-flex flex-shrink-0 items-center rounded bg-red-500/90 px-1.5 py-0.5 text-xs font-medium text-white">{{ __('Will Record') }}</span>
                                    @else
                                        @if ($airing['skip_reason'] === 'already_scheduled')
                                            <span class="inline-flex flex-shrink-0 items-center rounded bg-gray-400/90 px-1.5 py-0.5 text-xs font-medium text-white">{{ __('Skipped - Already Scheduled') }}</span>
                                        @elseif ($airing['skip_reason'] === 'already_recorded')
                                            <span class="inline-flex flex-shrink-0 items-center rounded bg-gray-400/90 px-1.5 py-0.5 text-xs font-medium text-white">{{ __('Skipped - Already Recorded') }}</span>
                                        @else
                                            <span class="inline-flex flex-shrink-0 items-center rounded bg-gray-400/90 px-1.5 py-0.5 text-xs font-medium text-white">{{ __('Skipped') }}</span>
                                        @endif
                                    @endif
                                @endif

                                {{-- Flags --}}
                                @if ($airing['is_new'])
                                    <span class="inline-flex flex-shrink-0 items-center rounded bg-emerald-500/90 px-1.5 py-0.5 text-xs font-medium text-white">{{ __('New') }}</span>
                                @endif
                                @if ($airing['premiere'])
                                    <span class="inline-flex flex-shrink-0 items-center rounded bg-purple-500/90 px-1.5 py-0.5 text-xs font-medium text-white">{{ __('Premiere') }}</span>
                                @endif
                            </div>

                            {{-- Episode subtitle --}}
                            @if ($airing['subtitle'])
                                <p class="mt-0.5 truncate text-xs text-gray-600 dark:text-gray-300">
                                    {{ $airing['subtitle'] }}
                                </p>
                            @endif

                            {{-- Description --}}
                            @if ($airing['description'])
                                <p class="mt-0.5 line-clamp-2 text-xs text-gray-400 dark:text-gray-500">
                                    {{ $airing['description'] }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@else
    <div class="fi-fo-field-wrp col-span-full">
        <div class="rounded-lg border border-dashed border-gray-300 px-4 py-6 text-center dark:border-white/10">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('No upcoming airings found for this series in the next 14 days.') }}
            </p>
        </div>
    </div>
@endif
