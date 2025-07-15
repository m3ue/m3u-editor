<x-filament::modal icon="heroicon-o-qr-code" alignment="center">
    <x-slot name="trigger">
        <x-filament::button icon="heroicon-m-qr-code" color="gray">
            {{ $label ?? 'QR Code' }}
        </x-filament::button>
    </x-slot>

    <x-slot name="heading">
        {{ $title }}
    </x-slot>

    <div class="relative flex flex-col gap-2 items-center w-auto" wire:ignore>
        <p>
            {{ $body }}
        </p>
        <div class="flex items-center justify-center">
            <div class="qr-code rounded-lg overflow-hidden ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" data-text="{{ $text }}" data-size="250"></div>
        </div>
    </div>
</x-filament::modal>
