<x-filament-widgets::widget class="fi-wi-table">
    {{--
        Wrap the cache activity table in a <x-filament::section> so it
        reads as a discrete "clustered section" on the Dynamic Groups
        listing page — same UX shape as the playlist form's Dynamic
        Groups (TMDB) section (icon + heading + content), not just a
        stacked footer widget.

        The section is collapsed by default when there are no rows for
        this content_type, mirroring the per-type canView() gate on
        the base class — keeps the VOD/Series page tight when only the
        other type has any cache activity yet. Once any row exists for
        this widget's type, the section auto-expands.

        The `?` tooltip in `afterHeader` follows the same pattern as
        the old DynamicGroupsWidget: lives there deliberately because
        the section's click-to-collapse handler excludes clicks inside
        `.fi-section-header-after-ctn`, so hovering/clicking the
        icon-button never accidentally toggles the collapse state.
    --}}
    <x-filament::section
        wire:key="dynamic-group-cache-activity-section-{{ $this->hasCacheActivity() ? 'expanded' : 'collapsed' }}"
        :icon="$this->getSectionIcon()"
        :heading="$this->getSectionHeading()"
        :description="$this->getSectionDescription()"
        collapsible
        :collapsed="! $this->hasCacheActivity()"
        class="fi-section-dynamic-group-cache-activity"
    >
        <x-slot name="afterHeader">
            <x-filament::icon-button
                icon="heroicon-m-question-mark-circle"
                color="gray"
                :tooltip="$this->getSectionDescription()"
                :label="$this->getSectionDescription()"
            />
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-widgets::widget>
