@php($channelId = (int) ($getRecord()?->getKey() ?? 0))

<x-filament::icon-button
    icon="heroicon-m-arrows-right-left"
    color="gray"
    size="sm"
    :label="__('Drag onto a group on the left to move this channel')"
    class="ml-2 cursor-grab active:cursor-grabbing"
    draggable="true"
    x-on:dragstart="
        $event.dataTransfer.effectAllowed = 'move';
        $event.dataTransfer.setData('text/plain', '{{ $channelId }}');
    "
/>
