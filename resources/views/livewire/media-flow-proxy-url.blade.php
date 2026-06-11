@php($urls = \App\Facades\PlaylistFacade::getMediaFlowProxyUrls($this->record))
@php($m3uUrl = $urls['m3u'])
@php($epgUrl = $urls['epg'])
@php($xtream = $urls['xtream'])
<div class="space-y-4">
    @if (in_array($section, ['all', 'links']))
        <div class="lg:grid gap-4 grid-cols-2 mb-4">
            <div>
                <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                    M3U URL
                </span>
                <div class="flex gap-2 items-center justify-start">
                    <x-filament::input.wrapper suffix-icon="heroicon-m-globe-alt">
                        <x-slot name="prefix">
                            <x-copy-to-clipboard :text="$m3uUrl" />
                        </x-slot>
                        <x-filament::input type="text" :value="$m3uUrl" readonly />
                    </x-filament::input.wrapper>
                    <x-qr-modal :title="$this->record->name" body="MediaFlow Proxy — M3U URL" :text="$m3uUrl" />
                </div>
            </div>
            <div>
                <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                    EPG URL
                </span>
                <div class="flex gap-2 items-center justify-start">
                    <x-filament::input.wrapper suffix-icon="heroicon-m-globe-alt">
                        <x-slot name="prefix">
                            <x-copy-to-clipboard :text="$epgUrl" />
                        </x-slot>
                        <x-filament::input type="text" :value="$epgUrl" readonly />
                    </x-filament::input.wrapper>
                    <x-qr-modal :title="$this->record->name" body="MediaFlow Proxy — EPG URL" :text="$epgUrl" />
                </div>
            </div>
        </div>
    @endif

    @if (in_array($section, ['all', 'xtream']))
        <div class="lg:grid gap-4 grid-cols-2">
            {{-- Left: default credentials --}}
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                    Use the following credentials in any Xtream-compatible player to stream through MediaFlow Proxy.
                </p>
                <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                    Default Authentication
                </span>
                <div class="flex gap-2 items-center justify-start mb-4">
                    <x-filament::input.wrapper suffix-icon="heroicon-m-globe-alt">
                        <x-slot name="prefix">
                            <x-copy-to-clipboard :text="$xtream['server']" />
                        </x-slot>
                        <x-filament::input type="text" :value="$xtream['server']" readonly />
                    </x-filament::input.wrapper>
                    <x-qr-modal :title="$this->record->name" body="MediaFlow Proxy — Server" :text="$xtream['server']" />
                </div>
                <div class="flex gap-2 items-center justify-start mb-4">
                    <x-filament::input.wrapper suffix-icon="heroicon-m-user">
                        <x-slot name="prefix">
                            <x-copy-to-clipboard :text="$xtream['default']['username']" />
                        </x-slot>
                        <x-filament::input type="text" :value="$xtream['default']['username']" readonly />
                    </x-filament::input.wrapper>
                    <x-qr-modal :title="$this->record->name" body="MediaFlow Proxy — Username" :text="$xtream['default']['username']" />
                </div>
                <div class="flex gap-2 items-center justify-start">
                    <x-filament::input.wrapper suffix-icon="heroicon-m-lock-closed">
                        <x-slot name="prefix">
                            <x-copy-to-clipboard :text="$xtream['default']['password']" />
                        </x-slot>
                        <x-filament::input type="text" :value="$xtream['default']['password']" readonly />
                    </x-filament::input.wrapper>
                    <x-qr-modal :title="$this->record->name" body="MediaFlow Proxy — Password" :text="$xtream['default']['password']" />
                </div>
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400 mb-2">
                    The default username is your <strong>m3u editor</strong> username and the Playlist <strong>unique
                        identifier</strong> is the password, encoded for MediaFlow Proxy.
                </p>
            </div>

            {{-- Right: per-auth credentials --}}
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                    You can also use your assigned <strong>Playlist Auths</strong> to access via MediaFlow Proxy.
                </p>
                @if (empty($xtream['auths']))
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-2">
                        <div class="flex items-center justify-center h-32">
                            <div
                                class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4">
                                <x-heroicon-o-lock-closed class="w-8 h-8 text-gray-400 dark:text-gray-600" />
                            </div>
                        </div>
                        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                            No Auths Available
                        </span>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                            You can create and assign them to your playlist in the <a
                                href="{{ url('/playlist-auths') }}"
                                class="text-blue-600 dark:text-blue-400 hover:underline">Playlist Auths</a> section.
                        </p>
                    </div>
                @else
                    @foreach ($xtream['auths'] as $auth)
                        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                            Auth: {{ $auth['name'] }}
                        </span>
                        <div class="flex gap-2 items-center justify-start mb-4">
                            <x-filament::input.wrapper suffix-icon="heroicon-m-user">
                                <x-slot name="prefix">
                                    <x-copy-to-clipboard :text="$auth['username']" />
                                </x-slot>
                                <x-filament::input type="text" :value="$auth['username']" readonly />
                            </x-filament::input.wrapper>
                            <x-qr-modal :title="$this->record->name" body="MediaFlow Proxy — Username" :text="$auth['username']" />
                        </div>
                        <div class="flex gap-2 items-center justify-start mb-4">
                            <x-filament::input.wrapper suffix-icon="heroicon-m-lock-closed">
                                <x-slot name="prefix">
                                    <x-copy-to-clipboard :text="$auth['password']" />
                                </x-slot>
                                <x-filament::input type="text" :value="$auth['password']" readonly />
                            </x-filament::input.wrapper>
                            <x-qr-modal :title="$this->record->name" body="MediaFlow Proxy — Password" :text="$auth['password']" />
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    @endif

    <div class="w-full fi-fo-field-wrp-helper-text break-words text-sm text-center text-gray-500 mt-1">
        To disable, clear the MediaFlow Proxy values from the app <a
            href="{{ url('preferences?tab=integrations%3A%3Adata%3A%3Atab') }}"
            class="text-indigo-500 hover:underline hover:text-indigo-600 dark:hover:text-indigo-400">Settings</a> page.
    </div>
</div>
