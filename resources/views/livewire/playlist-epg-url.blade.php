@php($urls = \App\Facades\PlaylistFacade::getUrls($this->record))
@php($epgUrl = $urls['epg'])
@php($epgZippedUrl = $urls['epg_zip'])
<div class="space-y-6">
    <x-output-url-field
        label="Uncompressed EPG URL (XMLTV format)"
        :enabled="$this->record->xmltv_enabled"
        :url="$epgUrl"
        suffix=".xml"
        qr-body="EPG URL"
        :record-name="$this->record->name"
        description="Use the following URL to access your EPG in XML format."
        :disabled-description="__('XMLTV output is disabled for this playlist. Enable it in the playlist settings to generate a URL.')"
    />

    <x-output-url-field
        label="Compressed EPG URL (GZIP format)"
        :enabled="$this->record->xmltv_enabled"
        :url="$epgZippedUrl"
        suffix=".xml.gz"
        qr-body="EPG URL (compressed)"
        :record-name="$this->record->name"
        description="Use the following URL to access your EPG in GZIP format."
        :disabled-description="__('XMLTV output is disabled for this playlist. Enable it in the playlist settings to generate a URL.')"
    />

    <x-filament::modal id="{{ $modalId }}" icon="heroicon-o-trash" icon-color="warning" alignment="center" size="sm">
        <x-slot name="trigger">
            <x-filament::button icon="heroicon-o-trash" color="gray" size="xs">
                Clear Playlist EPG File Cache
            </x-filament::button>
        </x-slot>

        <x-slot name="heading">Clear Playlist EPG File Cache</x-slot>

        Clear the EPG file cache for this playlist? It will be automatically regenerated on the next download.

        <x-slot name="footer">
            <div class="grid w-full grid-cols-2 gap-2">
                <x-filament::button
                    wire:click="$dispatch('close-modal', { id: '{{ $modalId }}' })"
                    label="Cancel"
                    color="gray"
                    class="w-full"
                >
                    Cancel
                </x-filament::button>
                <x-filament::button
                    wire:click="clearEpgFileCache"
                    label="Clear Playlist EPG File Cache"
                    color="warning"
                    class="w-full"
                >
                    Confirm
                </x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
</div>
