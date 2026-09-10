<x-filament-panels::page>
    <div class="space-y-4">
        <div class="max-w-xl">{{ $this->playlistPickerForm }}</div>

        @if ($playlistId)
            @php($paneKey = $playlistId . '-' . $contentType)
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
                {{-- Left pane: groups --}}
                <div class="w-full lg:w-[26rem] lg:shrink-0 xl:w-[30rem]">
                    @livewire('easy-editor.groups-pane', ['playlistId' => $playlistId, 'contentType' => $contentType, 'selectedGroupId' => $selectedGroupId], 'easy-editor-groups-'.$paneKey)
                </div>

                {{-- Right pane: channels for the selected group --}}
                <div class="w-full min-w-0 flex-1">
                    @if ($selectedGroupId)
                        @livewire('easy-editor.channels-pane', ['playlistId' => $playlistId, 'contentType' => $contentType, 'selectedGroupId' => $selectedGroupId], 'easy-editor-channels-'.$paneKey.'-'.$selectedGroupId)
                    @else
                        <x-filament::section>
                            <div class="flex flex-col items-center justify-center gap-2 py-16 text-center">
                                <x-filament::icon
                                    icon="heroicon-o-arrow-left-circle"
                                    class="h-8 w-8 text-gray-400 dark:text-gray-500"
                                />
                                <p class="text-sm font-medium text-gray-950 dark:text-white">
                                    {{ __('Select a group') }}
                                </p>
                                <p class="max-w-sm text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('Pick a group on the left to view, edit and sort its channels. Channels are always edited one group at a time.') }}
                                </p>
                            </div>
                        </x-filament::section>
                    @endif
                </div>
            </div>
        @else
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Create a playlist first, then come back here to edit its groups and channels.') }}
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
