<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($info = \App\Facades\PlaylistFacade::getXtreamInfo($record))
    @php($url = $info['url'])
    @php($username = $info['username'])
    @php($password = $info['password'])
    @php($auths = $record->playlistAuths)
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }">
        <div class="grid-cols-2 gap-4 lg:grid">
            <div>
                <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
                    Use the following url and credentials to access your playlist using the Xtream API.
                </p>
                <span class="text-sm leading-6 font-medium text-gray-950 dark:text-white">
                    Default Authentication
                </span>
                <div class="mb-4 flex items-center justify-start gap-2">
                    <x-filament::input.wrapper suffix-icon="heroicon-m-globe-alt">
                        <x-slot name="prefix">
                            <x-copy-to-clipboard :text="$url" />
                        </x-slot>
                        <x-filament::input type="text" :value="$url" readonly />
                    </x-filament::input.wrapper>
                    <x-qr-modal :title="$record->name" body="Xtream API URL" :text="$url" />
                </div>
                @if ($password === null)
                    <div class="flex items-center gap-2">
                        <x-filament::badge color="danger" size="sm">{{ __('Disabled') }}</x-filament::badge>
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('The default login is disabled for this playlist. Use one of its Playlist Auths to log in.') }}
                        </span>
                    </div>
                @else
                    <div class="mb-4 flex items-center justify-start gap-2">
                        <x-filament::input.wrapper suffix-icon="heroicon-m-user">
                            <x-slot name="prefix">
                                <x-copy-to-clipboard :text="$username" />
                            </x-slot>
                            <x-filament::input type="text" :value="$username" readonly />
                        </x-filament::input.wrapper>
                        <x-qr-modal :title="$record->name" body="Xtream API Username" :text="$username" />
                    </div>
                    <div class="flex items-center justify-start gap-2">
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
                        @if ($password !== 'YOUR_M3U_EDITOR_PASSWORD')
                            <x-qr-modal :title="$record->name" body="Xtream API Password" :text="$password" />
                        @endif
                    </div>
                    @if ($info['mode'] === \App\Enums\DefaultAuthMode::Uuid)
                        <p class="mt-4 mb-2 text-sm text-gray-500 dark:text-gray-400">
                            The default username is your <strong>m3u editor</strong> username and the Playlist
                            <strong>unique identifier</strong> is the password.
                        </p>
                    @elseif ($info['mode'] === \App\Enums\DefaultAuthMode::Custom)
                        <p class="mt-4 mb-2 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('The default username is your m3u editor username and the password is the custom password set in this playlist\'s Auth settings.') }}
                        </p>
                    @endif
                @endif
            </div>
            <div>
                <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
                    You can also use your assigned <strong>Playlist Auths</strong> to access the Xtream API.
                </p>
                @if ($auths->isEmpty())
                    <div class="rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                        <div class="flex h-32 items-center justify-center">
                            <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                                <x-heroicon-o-lock-closed class="h-8 w-8 text-gray-400 dark:text-gray-600" />
                            </div>
                        </div>
                        <span class="text-sm leading-6 font-medium text-gray-950 dark:text-white">
                            No Auths Available
                        </span>
                        <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
                            You can create and assign them to your playlist in the
                            <a
                                href="{{ url('/playlist-auths') }}"
                                class="text-blue-600 hover:underline dark:text-blue-400"
                            >Playlist Auths</a>
                            section.
                        </p>
                    </div>
                @else
                    @foreach ($auths as $auth)
                        <span class="text-sm leading-6 font-medium text-gray-950 dark:text-white">
                            Auth: {{ $auth->name }}
                        </span>
                        <div class="mb-4 flex items-center justify-start gap-2">
                            <x-filament::input.wrapper suffix-icon="heroicon-m-user">
                                <x-slot name="prefix">
                                    <x-copy-to-clipboard :text="$auth->username" />
                                </x-slot>
                                <x-filament::input type="text" :value="$auth->username" readonly />
                            </x-filament::input.wrapper>
                            <x-qr-modal :title="$record->name" body="Xtream API Username" :text="$auth->username" />
                        </div>
                        <div class="mb-4 flex items-center justify-start gap-2">
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
                            @if ($auth->password !== 'YOUR_M3U_EDITOR_PASSWORD')
                                <x-qr-modal :title="$record->name" body="Xtream API Password" :text="$auth->password" />
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</x-dynamic-component>
