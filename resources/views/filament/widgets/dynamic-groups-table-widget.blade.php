<x-filament-widgets::widget class="fi-wi-table">
    <x-filament::section
        icon="heroicon-o-sparkles"
        :heading="__('Dynamic Groups (TMDB)')"
        collapsible
        :collapsed="! $this->hasDynamicGroups()"
    >
        <x-slot name="afterHeader">
            {{-- Always-visible "?" tooltip. Lives in `afterHeader` deliberately:
                 the section's header click-to-collapse handler excludes clicks
                 inside `.fi-section-header-after-ctn`, so hovering/clicking the
                 icon-button here never accidentally toggles the collapse state.
                 Same idiom as the `->hintIcon()` pattern used throughout this
                 app on form fields (e.g. ChannelResource, EmbyLibraryMappingsRelationManager). --}}
            <x-filament::icon-button
                icon="heroicon-m-question-mark-circle"
                color="gray"
                :tooltip="$this->getDynamicGroupsHelpText()"
                :label="$this->getDynamicGroupsHelpText()"
            />
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-widgets::widget>
