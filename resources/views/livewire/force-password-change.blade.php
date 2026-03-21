<div x-data="{ show: @entangle('show').live }" x-show="show" x-cloak style="z-index: 99999;"
    class="fixed inset-0 flex items-center justify-center" aria-modal="true" role="dialog">
    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-gray-950/75 backdrop-blur-sm"></div>

    {{-- Modal panel --}}
    <div
        class="relative w-full max-w-md mx-4 bg-white dark:bg-gray-900 rounded-xl shadow-2xl ring-1 ring-gray-950/5 dark:ring-white/10 p-8">

        <div class="mb-6 text-center">
            <div
                class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-danger-100 dark:bg-danger-500/20 mb-4">
                <x-heroicon-o-lock-closed class="w-7 h-7 text-danger-600 dark:text-danger-400" />
            </div>
            <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                Change your password
            </h2>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                You are using the default password. You must set a new password before continuing.
            </p>
        </div>

        <form wire:submit="save" class="space-y-5">

            <div>
                <label for="fpc-password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    New password
                </label>
                <input id="fpc-password" type="password" wire:model="password" autocomplete="new-password"
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-950 dark:text-white shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                    placeholder="Minimum 8 characters" />
                @error('password')
                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="fpc-password-confirm"
                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Confirm new password
                </label>
                <input id="fpc-password-confirm" type="password" wire:model="password_confirmation"
                    autocomplete="new-password"
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-950 dark:text-white shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                    placeholder="Repeat new password" />
                @error('password_confirmation')
                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-primary-600 hover:bg-primary-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 transition"
                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed">
                <span wire:loading.remove wire:target="save">Update password</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>

        </form>

    </div>
</div>