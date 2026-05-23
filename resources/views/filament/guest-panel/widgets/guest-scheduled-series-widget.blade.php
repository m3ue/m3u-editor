@php
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\DvrRecordingRule> $rules */
    $rules = $this->getSeriesRules();
@endphp

@if ($rules->isNotEmpty())
    <x-filament-widgets::widget>
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Scheduled Series') }}
            </x-slot>

            <x-slot name="description">
                {{ __('These series rules will record matching episodes as they air. Individual recordings appear here once scheduled (within 30 minutes of the episode start time).') }}
            </x-slot>

            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($rules as $rule)
                    <div class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                {{ $rule->series_title }}
                            </p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                @if ($rule->channel)
                                    {{ $rule->channel->display_title }}
                                @elseif ($rule->source_channel_id)
                                    {{ __('From original source') }}
                                @else
                                    {{ __('Any channel') }}
                                @endif
                                &middot;
                                {{ __('P:') }}{{ $rule->priority }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <x-filament::badge color="info">
                                {{ __('Series') }}
                            </x-filament::badge>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </x-filament-widgets::widget>
@endif
