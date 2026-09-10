@php($channelId = (int) ($getRecord()?->getKey() ?? 0))

{{--
    Cross-pane (channels table -> groups table) drag/drop. Filament has no
    cross-table drag primitive, so the drag source is a stock
    <x-filament::icon-button> with the native HTML5 drag events wired on by hand;
    the drop side lives in group-drop-target.blade.php.
--}}
<x-filament::icon-button
    icon="heroicon-m-arrows-right-left"
    color="gray"
    size="sm"
    :tooltip="__('Drag onto a group on the left to move this channel')"
    :label="__('Drag onto a group on the left to move this channel')"
    class="ml-2 cursor-grab active:cursor-grabbing"
    draggable="true"
    x-on:dragstart="
        $event.dataTransfer.effectAllowed = 'move';
        $event.dataTransfer.setData('text/plain', '{{ $channelId }}');
    "
/>
