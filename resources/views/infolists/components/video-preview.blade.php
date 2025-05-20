<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @php($record = $getRecord())
    @php($url = $record->url_custom ?? $record->url)
    @php($proxyUrl = App\Facades\ProxyFacade::getProxyUrlForChannel($record->id, 'mp4'))
    @php($playerId = "channel_{$record->id}_preview")
    <div x-data="{ state: {}, player: null }">
        <div x-data x-init="
            player = videojs('{{ $playerId }}', { fluid: true, responsive: true, liveui: true });
            player.on('loadedmetadata', function() {
                player.duration = function() { return Infinity; };
                player.trigger('durationchange');
            });
        " x-on:close-modal.window="player.dispose();">
            <video-js id="{{ $playerId }}"
                class="video-js vjs-fluid vjs-16-9 vjs-default-skin" 
                preload="auto" data-setup="{}" controls>
                <source src="{{ $proxyUrl }}" type="video/mp4">
            </video-js>
        </div>
    </div>
</x-dynamic-component>
