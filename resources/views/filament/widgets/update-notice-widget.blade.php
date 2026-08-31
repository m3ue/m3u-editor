<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center gap-x-3">
            <div>
                <a href="https://github.com/{{ $versionData['repo'] }}" rel="noopener noreferrer" target="_blank">
                    @include('filament.admin.logo')
                </a>
            </div>

            <div class="flex-1">
                <div class="flex flex-1 items-start gap-x-2">
                    <h2 class="grid text-base leading-6 font-semibold text-gray-950 dark:text-white">
                        v{{ $versionData['version'] }}
                    </h2>
                    @if ($versionData['branch'])
                        <x-filament::badge
                            x-tooltip="'{{ __('Commit') }}: {{ $versionData['commit'] }}'"
                            class="cursor-pointer"
                            size="sm"
                            color="primary"
                        >
                            {{ $versionData['branch'] }}
                        </x-filament::badge>
                    @endif
                </div>

                @if ($versionData['updateAvailable'])
                    <div>
                        <div class="flex items-center gap-x-1">
                            <x-heroicon-o-exclamation-triangle class="text-danger h-4 w-4" />
                            <p class="text-sm font-bold text-gray-700 dark:text-gray-200">
                                {{ __('A new version is available') }}
                            </p>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Latest version: :version', ['version' => 'v' . $versionData['latestVersion']]) }}
                        </p>
                    </div>
                @else
                    <div>
                        <div class="flex items-center gap-x-1">
                            <x-heroicon-o-check-circle class="text-success h-4 w-4" />
                            <p class="text-sm font-bold text-gray-700 dark:text-gray-200">{{ __('Up to date') }}</p>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('You are using the latest version') }}
                        </p>
                    </div>
                @endif
            </div>

            <div class="flex flex-col items-end gap-y-1">
                <x-filament::button
                    color="{{ $versionData['updateAvailable'] ? 'danger' : 'gray' }}"
                    tag="a"
                    size="sm"
                    href="https://github.com/{{ $versionData['repo'] }}/releases"
                    icon="heroicon-m-arrow-top-right-on-square"
                    icon-alias="panels::widgets.filament-info.open-documentation-button"
                    rel="noopener noreferrer"
                    target="_blank"
                >
                    {{ __('Releases') }}
                </x-filament::button>
                @if (auth()->user()->canViewReleaseLogs())
                    <x-filament::button
                        class="mt-2"
                        color="gray"
                        icon="heroicon-m-newspaper"
                        tag="a"
                        size="sm"
                        href="{{ \App\Filament\Pages\ReleaseLogs::getUrl() }}"
                    >
                        {{ __('Release logs') }}
                    </x-filament::button>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
