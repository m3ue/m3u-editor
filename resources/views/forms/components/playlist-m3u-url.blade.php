<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($urls = \App\Facades\PlaylistUrlFacade::getUrls($record))
    @php($m3uUrl = $urls['m3u'])
    @php($hdhrUrl = $urls['hdhr'])
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }">
        <div class="flex gap-2 items-center justify-start mb-4">
            <x-filament::input.wrapper>
                <x-slot name="prefix">
                   <x-copy-to-clipboard :text="$m3uUrl" />
                </x-slot> 
                <x-filament::input
                    type="text"
                    :value="$m3uUrl"
                    readonly
                />
                <x-slot name="suffix">
                    .m3u
                </x-slot>
            </x-filament::input.wrapper>
            <x-qr-modal :title="$record->name" body="M3U URL" :text="$m3uUrl" />
        </div>
        <div class="flex gap-2 items-center justify-start">
            <x-filament::input.wrapper>
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$hdhrUrl" />
                </x-slot>
                <x-filament::input
                    type="text"
                    :value="$hdhrUrl"
                    readonly
                />
                <x-slot name="suffix">
                    hdhr
                </x-slot>
            </x-filament::input.wrapper>
            <x-qr-modal :title="$record->name" body="HDHR URL" :text="$hdhrUrl" />
        </div>
    </div>
</x-dynamic-component>
