<x-filament-widgets::widget class="fi-filament-info-widget">
    <x-filament::section>
        <div class="w-full space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="flex items-center gap-2 text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    <x-filament::icon icon="heroicon-s-circle-stack" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    {{ __('DVR Storage') }}
                </h2>
            </div>

            @if ($rows->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('User') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Recordings') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Used') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Quota') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Usage') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="px-3 py-2.5 text-gray-950 dark:text-white">{{ $row['user']?->name ?? '—' }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums text-gray-950 dark:text-white">{{ number_format($row['recording_count']) }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums text-gray-950 dark:text-white">{{ $row['used_formatted'] }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums text-gray-950 dark:text-white">{{ $row['quota_formatted'] }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">
                                        @if ($row['percent'] !== null)
                                            <x-filament::badge :color="match (true) {
                                                $row['percent'] >= 90 => 'danger',
                                                $row['percent'] >= 75 => 'warning',
                                                default => 'success',
                                            }">
                                                {{ $row['percent'] }}%
                                            </x-filament::badge>
                                        @else
                                            <span class="text-gray-400 dark:text-gray-500">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-gray-400 dark:text-gray-500">{{ __('No DVR settings configured.') }}</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
