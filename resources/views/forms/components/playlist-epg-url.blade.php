<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($urls = \App\Facades\PlaylistUrlFacade::getUrls($record))
    @php($epgUrl = $urls['epg'])
    @php($epgZippedUrl = $urls['epg_zip'])
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }">
        <div class="flex gap-2 items-center justify-start mb-4">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    :value="$epgUrl"
                    readonly
                />
                <x-slot name="suffix">
                    .xml
                </x-slot>
            </x-filament::input.wrapper>
            <x-qr-modal :title="$record->name" body="EPG URL" :text="$epgUrl" />
        </div>
        <div class="flex gap-2 items-center justify-start">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    :value="$epgZippedUrl"
                    readonly
                />
                <x-slot name="suffix">
                    .xml.gz
                </x-slot>
            </x-filament::input.wrapper>
            <x-qr-modal :title="$record->name" body="EPG URL (compressed)" :text="$epgZippedUrl" />
        </div>
    </div>
</x-dynamic-component>
