<x-filament-widgets::widget class="fi-wi-table">
    <x-filament::section
        {{--
            Alpine's `isCollapsed` is only set from `:collapsed` on initial
            `x-data` evaluation. A reactive re-render (e.g. switching
            playlist tabs into one with no Dynamic Groups) morphs this
            element in place rather than recreating it, so the collapse
            state would otherwise get stuck at whatever it was on first
            mount. Keying on `hasDynamicGroups()` forces Livewire to treat
            a flip between empty/non-empty as a new element, so the
            collapse state re-syncs on every such change.
        --}}
        wire:key="dynamic-groups-section-{{ $this->hasDynamicGroups() ? 'expanded' : 'collapsed' }}"
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
