<x-filament-widgets::widget class="fi-filament-info-widget">
    <x-filament::section>
        <div class="flex items-center gap-x-3">
            <div>
                <a href="{{ config('dev.docs_url') }}" rel="noopener noreferrer" target="_blank">
                    <svg width="2.7rem" height="2.7rem" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                        <defs>
                            <linearGradient id="linear-gradient-docs" x1="0" y1="35" x2="20" y2="40" gradientUnits="userSpaceOnUse">
                                <stop offset="0" stop-color="#f43f5e"/>
                                <stop offset=".37" stop-color="#b3509f"/>
                                <stop offset=".81" stop-color="#6366f1"/>
                            </linearGradient>
                        </defs>
                        <path style="fill: url(#linear-gradient-docs);" d="M11.25 4.533A9.707 9.707 0 0 0 6 3a9.735 9.735 0 0 0-3.25.555.75.75 0 0 0-.5.707v14.25a.75.75 0 0 0 1 .707A8.237 8.237 0 0 1 6 18.75c1.995 0 3.823.707 5.25 1.886V4.533ZM12.75 20.636A8.214 8.214 0 0 1 18 18.75c.966 0 1.89.166 2.75.47a.75.75 0 0 0 1-.708V4.262a.75.75 0 0 0-.5-.707A9.735 9.735 0 0 0 18 3a9.707 9.707 0 0 0-5.25 1.533v16.103Z" />
                    </svg>
                </a>
            </div>

            <div class="flex-1">
                <h2 class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    Documentation
                </h2>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Learn more about <strong>m3u editor</strong> and how to use it
                </p>
            </div>

            <div class="flex flex-col items-end gap-y-1">
                <x-filament::button color="gray" tag="a" href="{{ config('dev.docs_url') }}"
                    icon="heroicon-m-arrow-top-right-on-square"
                    icon-alias="panels::widgets.filament-info.open-documentation-button" rel="noopener noreferrer"
                    target="_blank">
                    Documentation
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
