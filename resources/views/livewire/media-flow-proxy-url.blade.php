@php($urls = \App\Facades\PlaylistFacade::getMediaFlowProxyUrls($this->record))
@php($m3uUrl = $urls['m3u'])
@php($epgUrl = $urls['epg'])
@php($xtream = $urls['xtream'])
<div class="space-y-4">
    @unless ($this->record->xapi_enabled)
        <div class="mb-4 flex items-center gap-2">
            <x-filament::badge color="danger" size="sm">{{ __('Disabled') }}</x-filament::badge>
            <span class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('MediaFlow Proxy access is disabled because Xtream API output is disabled for this playlist. Enable it in the playlist settings to allow access.') }}
            </span>
        </div>
    @endunless
    <div class="space-y-4 @unless ($this->record->xapi_enabled) pointer-events-none opacity-50 @endunless">
        @if (in_array($section, ['all', 'links']))
            <div class="mb-4 grid-cols-2 gap-4 lg:grid">
                <div>
                    <span class="text-sm leading-6 font-medium text-gray-950 dark:text-white"> M3U URL </span>
                    <div class="flex items-center justify-start gap-2">
                        <x-filament::input.wrapper suffix-icon="heroicon-m-globe-alt">
                            <x-slot name="prefix">
                                <x-copy-to-clipboard :text="$m3uUrl" />
                            </x-slot>
                            <x-filament::input type="text" :value="$m3uUrl" readonly />
                        </x-filament::input.wrapper>
                        <x-qr-modal :title="$this->record->name" body="MediaFlow Proxy - M3U URL" :text="$m3uUrl" />
                    </div>
                </div>
                <div>
                    <span class="text-sm leading-6 font-medium text-gray-950 dark:text-white"> EPG URL </span>
                    <div class="flex items-center justify-start gap-2">
                        <x-filament::input.wrapper suffix-icon="heroicon-m-globe-alt">
                            <x-slot name="prefix">
                                <x-copy-to-clipboard :text="$epgUrl" />
                            </x-slot>
                            <x-filament::input type="text" :value="$epgUrl" readonly />
                        </x-filament::input.wrapper>
                        <x-qr-modal :title="$this->record->name" body="MediaFlow Proxy - EPG URL" :text="$epgUrl" />
                    </div>
                </div>
            </div>
        @endif

        @if (in_array($section, ['all', 'xtream']))
            <div class="grid-cols-2 gap-4 lg:grid">
                {{-- Left: default credentials --}}
                <div>
                    <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
                        Use the following credentials in any Xtream-compatible player to stream through MediaFlow Proxy.
                    </p>
                    <span class="text-sm leading-6 font-medium text-gray-950 dark:text-white">
                        Default Authentication
                    </span>
                    <div class="mb-4 flex items-center justify-start gap-2">
                        <x-filament::input.wrapper suffix-icon="heroicon-m-globe-alt">
                            <x-slot name="prefix">
                                <x-copy-to-clipboard :text="$xtream['server']" />
                            </x-slot>
                            <x-filament::input type="text" :value="$xtream['server']" readonly />
                        </x-filament::input.wrapper>
                        <x-qr-modal
                            :title="$this->record->name"
                            body="MediaFlow Proxy - Server"
                            :text="$xtream['server']"
                        />
                    </div>
                    <div class="mb-4 flex items-center justify-start gap-2">
                        <x-filament::input.wrapper suffix-icon="heroicon-m-user">
                            <x-slot name="prefix">
                                <x-copy-to-clipboard :text="$xtream['default']['username']" />
                            </x-slot>
                            <x-filament::input type="text" :value="$xtream['default']['username']" readonly />
                        </x-filament::input.wrapper>
                        <x-qr-modal
                            :title="$this->record->name"
                            body="MediaFlow Proxy - Username"
                            :text="$xtream['default']['username']"
                        />
                    </div>
                    <div class="flex items-center justify-start gap-2">
                        <x-filament::input.wrapper suffix-icon="heroicon-m-lock-closed">
                            <x-slot name="prefix">
                                <x-copy-to-clipboard :text="$xtream['default']['password']" />
                            </x-slot>
                            <x-filament::input type="text" :value="$xtream['default']['password']" readonly />
                        </x-filament::input.wrapper>
                        <x-qr-modal
                            :title="$this->record->name"
                            body="MediaFlow Proxy - Password"
                            :text="$xtream['default']['password']"
                        />
                    </div>
                    <p class="mt-4 mb-2 text-sm text-gray-500 dark:text-gray-400">
                        The default username is your <strong>m3u editor</strong> username and the Playlist
                        <strong>unique identifier</strong> is the password, encoded for MediaFlow Proxy.
                    </p>
                </div>

                {{-- Right: per-auth credentials --}}
                <div>
                    <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
                        You can also use your assigned <strong>Playlist Auths</strong> to access via MediaFlow Proxy.
                    </p>
                    @if (empty($xtream['auths']))
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
                        @foreach ($xtream['auths'] as $auth)
                            <span class="text-sm leading-6 font-medium text-gray-950 dark:text-white">
                                Auth: {{ $auth['name'] }}
                            </span>
                            <div class="mb-4 flex items-center justify-start gap-2">
                                <x-filament::input.wrapper suffix-icon="heroicon-m-user">
                                    <x-slot name="prefix">
                                        <x-copy-to-clipboard :text="$auth['username']" />
                                    </x-slot>
                                    <x-filament::input type="text" :value="$auth['username']" readonly />
                                </x-filament::input.wrapper>
                                <x-qr-modal
                                    :title="$this->record->name"
                                    body="MediaFlow Proxy - Username"
                                    :text="$auth['username']"
                                />
                            </div>
                            <div class="mb-4 flex items-center justify-start gap-2">
                                <x-filament::input.wrapper suffix-icon="heroicon-m-lock-closed">
                                    <x-slot name="prefix">
                                        <x-copy-to-clipboard :text="$auth['password']" />
                                    </x-slot>
                                    <x-filament::input type="text" :value="$auth['password']" readonly />
                                </x-filament::input.wrapper>
                                <x-qr-modal
                                    :title="$this->record->name"
                                    body="MediaFlow Proxy - Password"
                                    :text="$auth['password']"
                                />
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div class="fi-fo-field-wrp-helper-text mt-1 w-full text-center text-sm break-words text-gray-500">
        To disable, clear the MediaFlow Proxy values from the app
        <a
            href="{{ \App\Filament\Clusters\Settings\Pages\ManageIntegrationSettings::getUrl(['tab' => 'mediaflow']) }}"
            class="text-indigo-500 hover:text-indigo-600 hover:underline dark:hover:text-indigo-400"
        >Settings</a>
        page.
    </div>
</div>
