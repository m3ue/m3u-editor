<div class="my-4">
    @php $isAuthed = $this->isGuestAuthenticated(); @endphp
    @if (!$isAuthed)
        <div class="max-w-md mx-auto py-6">
            <x-filament::section icon="heroicon-o-lock-closed" class="my-6">
                <x-slot name="heading">
                    Playlist Login
                </x-slot>
                <x-slot name="description">
                    Use the playlist Xtream API username and password to login.
                </x-slot>

                {{-- Display authentication error if exists --}}

                @if ($authError)
                    <div class="mb-4 text-red-600 dark:text-red-400">{{ $authError }}</div>
                @endif

                <form wire:submit.prevent="login">
                    {{ $this->form }}
                    <x-filament::button type="submit" class="w-full mt-4">
                        Login
                    </x-filament::button>
                </form>
            </x-filament::section>
        </div>
    @else
        <div class="flex items-end justify-end fixed z-50 top-4 right-4">
            {{-- Display current playlist name --}}
            {{-- Logout button --}}
            <x-filament::button type="button" size="sm" color="gray" icon="heroicon-o-arrow-left-on-rectangle"
                wire:click="logout" class="">
                Sign out
            </x-filament::button>
        </div>
        {{-- Authenticated dashboard content goes here --}}
        <div>
            <livewire:epg-viewer :record="$playlist" :view-only="true" :vod="false" :username="$guestUsername"
                :password="$guestPassword" />
        </div>
    @endif
</div>