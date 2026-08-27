<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-circle-stack" icon-color="gray" :heading="__('DVR storage')">
        <x-slot name="afterHeader">
            <div class="inline-flex items-center gap-2">
                <x-filament::button
                    color="gray"
                    tag="a"
                    size="sm"
                    href="{{ \App\Filament\Resources\PlaylistAuths\PlaylistAuthResource::getUrl() }}"
                    icon="heroicon-m-arrow-top-right-on-square"
                >
                    {{ __('Manage Auth') }}
                </x-filament::button>

                <x-filament::button
                    color="gray"
                    tag="a"
                    size="sm"
                    href="{{ \App\Filament\Resources\DvrRecordings\DvrRecordingResource::getUrl() }}"
                    icon="heroicon-m-arrow-top-right-on-square"
                >
                    {{ __('Manage Recordings') }}
                </x-filament::button>
            </div>
        </x-slot>

        @if ($rows->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="px-3 py-2 text-left text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                                {{ __('User') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                                {{ __('Recordings') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                                {{ __('Used') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                                {{ __('Quota') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                                {{ __('Usage') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-3 py-2.5 text-gray-950 dark:text-white">
                                    {{ $row['user']?->name ?? 'N/A' }}
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums">
                                    <x-filament::badge color="gray">
                                        {{ number_format($row['recording_count']) }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-3 py-2.5 text-right text-gray-950 tabular-nums dark:text-white">
                                    {{ $row['used_formatted'] }}
                                </td>
                                <td class="px-3 py-2.5 text-right text-gray-950 tabular-nums dark:text-white">
                                    {{ $row['quota_formatted'] }}
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums">
                                    @if ($row['percent'] !== null)
                                        <x-filament::badge
                                            :color="match (true) {
                                            $row['percent'] >= 90 => 'danger',
                                            $row['percent'] >= 75 => 'warning',
                                            default => 'success',
                                        }"
                                        >
                                            {{ $row['percent'] }}%
                                        </x-filament::badge>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500">N/A</span>
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
    </x-filament::section>
</x-filament-widgets::widget>
