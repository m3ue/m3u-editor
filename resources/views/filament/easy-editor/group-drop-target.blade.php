@php($groupId = (int) ($getRecord()?->getKey() ?? 0))

{{--
    Drop target for the cross-pane channel move (see channel-drag-handle.blade.php).
    Filament has no cross-table drag primitive, so this is a stock
    <x-filament::icon-button> with native HTML5 dragover/drop events wired on by
    hand; the drop calls $wire.moveChannelToGroup() on the Livewire component.
--}}
<x-filament::icon-button
    icon="heroicon-m-arrow-down-on-square"
    color="gray"
    size="sm"
    class="ml-2"
    :tooltip="__('Drop a channel here to move it into this group')"
    :label="__('Drop a channel here to move it into this group')"
    x-data="{ over: false }"
    x-on:dragover.prevent="over = true"
    x-on:dragenter.prevent="over = true"
    x-on:dragleave="over = false"
    x-on:drop.prevent="
        over = false;
        const channelId = parseInt($event.dataTransfer.getData('text/plain'), 10);
        if (channelId && {{ $groupId }}) { $wire.moveChannelToGroup(channelId, {{ $groupId }}); }
    "
    x-bind:class="over ? 'ring-2 ring-primary-500 ring-offset-1' : ''"
/>
