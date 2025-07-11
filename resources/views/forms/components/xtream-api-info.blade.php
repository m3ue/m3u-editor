<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($info = \App\Facades\PlaylistUrlFacade::getXtreamInfo($record))
    @php($url = $info['url'])
    @php($username = $info['username'])
    @php($password = $info['password'])
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }">
        <div class="">
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                Use the following url and credentials to access your playlist using the Xtream API.
            </p>
        </div>
        <div class="flex gap-2 items-center justify-start mb-4">
            <x-filament::input.wrapper suffix-icon="heroicon-m-globe-alt">
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$url" />
                 </x-slot> 
                <x-filament::input
                    type="text"
                    :value="$url"
                    readonly
                />
            </x-filament::input.wrapper>
            <x-qr-modal :title="$record->name" body="Xtream API URL" :text="$url" />
        </div>
        <div class="flex gap-2 items-center justify-start mb-4">
            <x-filament::input.wrapper suffix-icon="heroicon-m-user">
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$username" />
                 </x-slot> 
                <x-filament::input
                    type="text"
                    :value="$username"
                    readonly
                />
            </x-filament::input.wrapper>
            <x-qr-modal :title="$record->name" body="Xtream API Username" :text="$username" />
        </div>
        <div class="flex gap-2 items-center justify-start">
            <x-filament::input.wrapper suffix-icon="heroicon-m-lock-closed">
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$password" />
                 </x-slot> 
                <x-filament::input
                    type="text"
                    :value="$password === 'YOUR_M3U_EDITOR_PASSWORD' ? '' : $password"
                    :placeholder="$password === 'YOUR_M3U_EDITOR_PASSWORD' ? $password : ''"
                    readonly
                />
            </x-filament::input.wrapper>
            @if($password !== 'YOUR_M3U_EDITOR_PASSWORD')
                <x-qr-modal :title="$record->name" body="Xtream API Password" :text="$password" />
            @endif  
        </div>
        <div class="mt-4">
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                The default username is your <strong>m3u editor</strong> username and the Playlist <strong>unique identifier</strong> is the password.
            </p>
            @if($record->playlistAuths()->count() > 0)
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                    You can also use your assigned <strong>Playlist Auths</strong> to access the Xtream API.
                </p>
            @endif
        </div>
    </div>
</x-dynamic-component>
