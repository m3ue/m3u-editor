<x-filament-panels::page>
    <div class="flex flex-col gap-y-8">
        @if($this->shouldDisplayStatusListRecords())
            <div class="mb-10">
                @livewire(ShuvroRoy\FilamentSpatieLaravelBackup\Components\BackupDestinationStatusListRecords::class)
            </div>
        @endif
        <div>
            @livewire(\App\Livewire\BackupDestinationListRecords::class)
            {{-- @livewire(ShuvroRoy\FilamentSpatieLaravelBackup\Components\BackupDestinationListRecords::class) --}}
        </div>
    </div>
</x-filament-panels::page>
