@php($urls = \App\Facades\PlaylistFacade::getUrls($this->record))
@php($m3uUrl = $urls['m3u'])
@php($hdhrUrl = $urls['hdhr'])
@php($publicUrl = url('/playlist/v/' . $this->record->uuid))
<div class="space-y-6">
    <x-output-url-field
        label="M3U URL"
        :enabled="$this->record->m3u_enabled"
        :url="$m3uUrl"
        suffix=".m3u"
        qr-body="M3U URL"
        :record-name="$this->record->name"
        description="Access playlist in M3U format."
        :disabled-description="__('M3U output is disabled for this playlist. Enable it in the playlist settings to generate a URL.')"
    />

    <x-output-url-field
        label="HDHR URL"
        :enabled="$this->record->hdhr_enabled"
        :url="$hdhrUrl"
        suffix="hdhr"
        qr-body="HDHR URL"
        :record-name="$this->record->name"
        description="Access playlist in HDHR format, for players like Plex and Jellyfin."
        :disabled-description="__('HDHR output is disabled for this playlist. Enable it in the playlist settings to generate a URL.')"
    />

    <div>
        <span class="text-sm leading-6 font-medium text-gray-950 dark:text-white"> Public URL </span>
        <div class="flex items-center justify-start gap-2">
            <x-filament::input.wrapper suffix-icon="heroicon-o-globe-alt">
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$publicUrl" />
                </x-slot>
                <x-filament::input type="text" :value="$publicUrl" readonly />
            </x-filament::input.wrapper>
            <x-qr-modal :title="$this->record->name" body="Public URL" :text="$publicUrl" />
        </div>
        <div class="fi-fo-field-wrp-helper-text mt-1 text-sm break-words text-gray-500">
            Access public page for this playlist (Xtream API authentication required).
        </div>
    </div>
</div>
