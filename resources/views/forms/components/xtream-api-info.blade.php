<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($info = \App\Facades\PlaylistFacade::getXtreamInfo($record))
    @php($url = $info['url'])
    @php($username = $info['username'])
    @php($password = $info['password'])
    @php($auths = $record->playlistAuths)
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }">
        <div class="lg:grid gap-4 grid-cols-2">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                    Use the following url and credentials to access your playlist using the Xtream API.
                </p>
                <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                    Default Authentication
                </span>
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
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400 mb-2">
                    The default username is your <strong>m3u editor</strong> username and the Playlist <strong>unique identifier</strong> is the password.
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                    You can also use your assigned <strong>Playlist Auths</strong> to access the Xtream API.
                </p>
                @if($auths->isEmpty())
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-2">
                        <div class="flex items-center justify-center h-32">
                            <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4">
                                <x-heroicon-o-lock-closed class="w-8 h-8 text-gray-400 dark:text-gray-600" />
                            </div>
                        </div>
                        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                            No Auths Available
                        </span>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                            You can create and assign them to your playlist in the <a href="{{ url('/playlist-auths') }}" class="text-blue-600 dark:text-blue-400 hover:underline">Playlist Auths</a> section.
                        </p>
                    </div>
                @else
                    @foreach($auths as $auth)
                        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                            Auth: {{ $auth->name }}
                        </span>
                        <div class="flex gap-2 items-center justify-start mb-4">
                            <x-filament::input.wrapper suffix-icon="heroicon-m-user">
                                <x-slot name="prefix">
                                    <x-copy-to-clipboard :text="$auth->username" />
                                </x-slot>
                                <x-filament::input
                                    type="text"
                                    :value="$auth->username"
                                    readonly
                                />
                            </x-filament::input.wrapper>
                            <x-qr-modal :title="$record->name" body="Xtream API Username" :text="$auth->username" />
                        </div>
                        <div class="flex gap-2 items-center justify-start mb-4">
                            <x-filament::input.wrapper suffix-icon="heroicon-m-lock-closed">
                                <x-slot name="prefix">
                                    <x-copy-to-clipboard :text="$auth->password" />
                                </x-slot>
                                <x-filament::input
                                    type="text"
                                    :value="$auth->password"
                                    :placeholder="$auth->password"
                                    readonly
                                />
                            </x-filament::input.wrapper>
                            @if($auth->password !== 'YOUR_M3U_EDITOR_PASSWORD')
                                <x-qr-modal :title="$record->name" body="Xtream API Password" :text="$auth->password" />
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</x-dynamic-component>
