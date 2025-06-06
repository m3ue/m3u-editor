<x-filament-widgets::widget class="fi-filament-info-widget">
    <x-filament::section>
        <div class="flex items-center gap-x-3">
            <div class="flex-shrink-0">
                <img src="{{ asset('images/crypto-icons/crypto-coins.svg') }}" alt="Crypto currencies" class="w-12 h-12">
            </div>

            <div class="flex-1">
                <h2 class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    Donate Crypto
                </h2>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    We accept donations in various cryptocurrencies.
                </p>
            </div>

            <div class="flex flex-col items-end gap-y-1">
                <x-filament::modal icon="heroicon-o-qr-code" alignment="center" width="4xl">
                    <x-slot name="trigger">
                        <x-filament::button color="gray">
                            Donate now ₿
                        </x-filament::button>
                    </x-slot>

                    <x-slot name="heading">
                        <div class="flex items-center gap-3">
                            <img src="{{ asset('/images/crypto-icons/crypto-coins.svg') }}" alt="Crypto currencies" class="w-8 h-8">
                            Donate Cryptocurrency
                        </div>
                    </x-slot>

                    <div class="space-y-6" x-init="$nextTick(() => { setTimeout(() => { if (typeof window.generateQRCodes === 'function') { window.generateQRCodes(); } }, 150); })" x-intersect="$nextTick(() => { setTimeout(() => { if (typeof window.generateQRCodes === 'function') { window.generateQRCodes(); } }, 50); })">
                        <div class="text-center">
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Have some spare coin? You can send it our way! We accept donations in various cryptocurrencies.
                                Simply scan the QR code or copy the address for your preferred currency.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            @foreach (config('dev.crypto_addresses') as $currency)
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-6 space-y-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="w-auto h-8 overflow-hidden ">
                                                <img src="{{ $currency['icon'] }}" 
                                                     alt="{{ $currency['name'] }} icon" 
                                                     class="w-full h-full">
                                            </div>
                                            <div>
                                                <h3 class="font-semibold text-gray-900 dark:text-white">
                                                    {{ $currency['name'] }} ({{ $currency['symbol'] }})
                                                </h3>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex justify-center">
                                        <div class="qr-code rounded-lg overflow-hidden ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" data-text="{{ $currency['address'] }}" data-size="175"></div>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-xs font-medium text-gray-700 dark:text-gray-300">Address:</label>
                                        <div class="bg-white dark:bg-gray-700 rounded border p-2">
                                            <code class="text-xs font-mono text-gray-600 dark:text-gray-300 break-all">{{ $currency['address'] }}</code>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-4">
                            <div class="flex items-start gap-3">
                                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5"/>
                                <div class="text-sm text-yellow-800 dark:text-yellow-200">
                                    <p class="font-medium mb-1">Important Notes:</p>
                                    <ul class="list-disc list-inside space-y-1 text-xs">
                                        <li>Double-check the address before sending</li>
                                        <li>Only send the corresponding cryptocurrency to each address</li>
                                        <li>Transactions are irreversible</li>
                                        <li>Network fees may apply</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </x-filament::modal>
            </div>
        </div>
    </x-filament::section>

    @push('scripts')
    <script>
        // This script is now mainly for fallback, as we use x-init in the modal for direct triggering
        document.addEventListener('DOMContentLoaded', function() {
            // Use MutationObserver as fallback to detect when modal content is added to DOM
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList') {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType === Node.ELEMENT_NODE) {
                                // Check if this is the modal or contains QR codes
                                const qrCodes = node.querySelectorAll ? node.querySelectorAll('.qr-code') : [];
                                if (qrCodes.length > 0 || node.classList?.contains('qr-code')) {
                                    // Small delay to ensure DOM is fully ready
                                    setTimeout(() => {
                                        if (typeof window.generateQRCodes === 'function') {
                                            window.generateQRCodes(null);
                                        }
                                    }, 100);
                                }
                            }
                        });
                    }
                });
            });

            // Start observing changes to the document body
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        });
    </script>
    @endpush
</x-filament-widgets::widget>
