<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center gap-x-3">
            <div>
                <a href="https://github.com/{{ $versionData['repo'] }}" rel="noopener noreferrer" target="_blank">
                    @include('filament.admin.logo')
                </a>
            </div>

            <div class="flex-1">
                <div class="flex-1 flex items-start gap-x-2">
                    <h2 class="grid text-base font-semibold leading-6 text-gray-950 dark:text-white">
                        v{{ $versionData['version'] }}
                    </h2>
                    @if ($versionData['branch'])
                        <x-filament::badge x-tooltip="'Commit: {{ $versionData['commit'] }}'" class="cursor-pointer"
                            size="sm" color="primary">
                            {{ $versionData['branch'] }}
                        </x-filament::badge>
                    @endif
                </div>

                @if ($versionData['updateAvailable'])
                    <div>
                        <div class="flex items-center gap-x-1">
                            <x-heroicon-o-exclamation-triangle class="w-4 h-4 text-danger" />
                            <p class="font-bold text-sm text-gray-700 dark:text-gray-200">
                                A new version is available
                            </p>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Latest version: v{{ $versionData['latestVersion'] }}
                        </p>
                    </div>
                @else
                    <div>
                        <div class="flex items-center gap-x-1">
                            <x-heroicon-o-check-circle class="w-4 h-4 text-success" />
                            <p class="font-bold text-sm text-gray-700 dark:text-gray-200">
                                Up to date
                            </p>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            You are using the latest version
                        </p>
                    </div>
                @endif
            </div>

            <div class="flex flex-col items-end gap-y-1">
                <x-filament::button color="{{ $versionData['updateAvailable'] ? 'danger' : 'gray' }}" tag="a"
                    href="https://github.com/{{ $versionData['repo'] }}/releases"
                    icon="heroicon-m-arrow-top-right-on-square"
                    icon-alias="panels::widgets.filament-info.open-documentation-button" rel="noopener noreferrer"
                    target="_blank">
                    Releases
                </x-filament::button>
                <x-filament::modal width="4xl">
                    <x-slot name="trigger">
                        <x-filament::button class="mt-2" color="gray" icon="heroicon-o-list-bullet">
                            Release logs
                        </x-filament::button>
                    </x-slot>

                    <div class="w-full min-w-0">
                        @if (!empty($releases))
                            <ul class="space-y-3 min-w-0">
                                @foreach ($releases as $release)
                                    <li
                                        class="p-3 rounded-lg bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 min-w-0">
                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <a href="{{ $release['url'] ?? '#' }}" target="_blank"
                                                        rel="noopener noreferrer" class="font-semibold text-sm">
                                                        {{ $release['name'] }}
                                                    </a>
                                                    @if (!empty($release['is_current']))
                                                        <span
                                                            class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs bg-green-100 text-green-800">
                                                            Current
                                                        </span>
                                                    @endif
                                                </div>
                                                @if (!empty($release['published_at']))
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        Released
                                                        {{ \Illuminate\Support\Carbon::parse($release['published_at'])->diffForHumans() }}
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="text-right text-xs">
                                                <x-filament::button href="{{ $release['url'] ?? '#' }}" tag="a"
                                                    icon="heroicon-s-arrow-top-right-on-square" size="sm" target="_blank">
                                                    View on GitHub
                                                </x-filament::button>
                                            </div>
                                        </div>

                                        @if (!empty($release['body']))
                                            <div
                                                class="mt-2 text-sm prose prose-sm dark:prose-invert font-mono release-body-content">
                                                {!! $this->formatMarkdown($release['body']) !!}
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <div class="text-sm text-gray-500">No release information available.</div>
                        @endif
                    </div>
                </x-filament::modal>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>