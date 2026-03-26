@php
    $counts = $this->getStatusTabCounts();
    $current = $this->statusFilter;

    $tabs = [
        'all' => ['label' => 'All VOD', 'count' => $counts['all'], 'color' => null],
        'enabled' => ['label' => 'Enabled', 'count' => $counts['enabled'], 'color' => 'success'],
        'disabled' => ['label' => 'Disabled', 'count' => $counts['disabled'], 'color' => 'danger'],
        'failover' => ['label' => 'Failover', 'count' => $counts['failover'], 'color' => 'info'],
        'custom' => ['label' => 'Custom', 'count' => $counts['custom'], 'color' => null],
    ];
@endphp

<div class="flex justify-center">
    <x-filament::tabs label="Status filter" class="w-auto">
        @foreach ($tabs as $key => $tab)
            <x-filament::tabs.item :active="$current === $key" :badge="$tab['count']" :badge-color="$tab['color']"
                wire:click="$set('statusFilter', '{{ $key }}')">
                {{ $tab['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
</div>