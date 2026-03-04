<div class="flex flex-col gap-2 items-center" wire:ignore
    x-init="setTimeout(() => { if (typeof window.generateQRCodes === 'function') { window.generateQRCodes('/logo.png'); } }, 100)">
    <div class="flex items-center justify-center">
        <div class="qr-code rounded-lg overflow-hidden ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
            data-text="{{ $text }}" data-size="250">
        </div>
    </div>
</div>